<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\ConsoleState;
use App\Filament\Support\DemoActions;
use App\Filament\Support\ResetDemo;
use BackedEnum;
use Carbon\CarbonImmutable;
use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Migration\Infrastructure\BackfillTrailDesk;
use Fellwise\Operations\ConsistencyChecker;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\ParkingLot;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Renderless;
use Throwable;
use UnitEnum;

/**
 * Live topology of the CMS ↔ Core pipeline. Every dot on the page is a
 * real hop recorded in ops.delivery_attempts; queue depths come from the
 * RabbitMQ management API; every button calls the real services.
 */
final class MissionControl extends Page
{
    protected string $view = 'filament.pages.mission-control';

    protected static ?string $slug = '/';

    protected static ?string $title = 'Mission Control';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShare;

    protected static string|UnitEnum|null $navigationGroup = 'Event sync';

    protected static ?int $navigationSort = 1;

    /**
     * @return array<string, mixed>
     */
    #[Renderless]
    public function state(int $after = 0): array
    {
        return app(ConsoleState::class)->snapshot($after);
    }

    #[Renderless]
    public function toggleFault(): bool
    {
        $faults = app(FaultInjector::class);
        app(DemoActions::class)->run('fault', function () use ($faults): void {
            $enabled = ! $faults->isFailing('core.catalogue');
            $faults->set('core.catalogue', $enabled);
            Notification::make()
                ->title($enabled ? 'Core consumer is failing' : 'Core consumer recovered')
                ->body($enabled
                    ? 'Watch messages retry for 5s → 15s → 45s, then park. The fault switches off automatically after 3 minutes in the shared demo.'
                    : 'New deliveries succeed again. Unpark or replay to repair what was parked.')
                ->color($enabled ? 'danger' : 'success')->send();
        }, 5);

        return $faults->isFailing('core.catalogue');
    }

    #[Renderless]
    public function runConsistencyCheck(): void
    {
        app(DemoActions::class)->run('check', function (): void {
            $result = app(ConsistencyChecker::class)->run();
            Notification::make()
                ->title($result['drifted'] === 0 ? "All {$result['checked']} experiences in sync" : "{$result['drifted']} experiences diverged")
                ->body("Compared indexed fingerprints in {$result['duration_ms']} ms.")
                ->color($result['drifted'] === 0 ? 'success' : 'danger')->send();
        }, 5);
    }

    #[Renderless]
    public function unparkAggregate(string $aggregateId): void
    {
        app(DemoActions::class)->run('unpark', function () use ($aggregateId): void {
            $moved = app(ParkingLot::class)->unpark('core.catalogue', fn ($envelope) => $envelope->aggregateId === $aggregateId);
            Notification::make()->title($moved > 0 ? "Unparked {$moved} message(s)" : 'Nothing parked for this experience')->info()->send();
        });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('resetDemo')
                ->label('Reset demo')->color('gray')->icon(Heroicon::OutlinedArrowPath)
                ->visible((bool) config('demo.enabled'))->requiresConfirmation()
                ->modalDescription('Switch off faults, clear both parking lots and resync the current state. Changes are shared with every visitor. Original sample data is restored nightly.')
                ->action(function (ResetDemo $reset): void {
                    app(DemoActions::class)->run('reset', function () use ($reset): void {
                        try {
                            $count = $reset->run();
                            Notification::make()->success()->title('Demo recovery started')->body("Faults are off, parking lots are clear, and {$count} events are resyncing. The consistency checker will update shortly.")->send();
                        } catch (Throwable $exception) {
                            report($exception);
                            Notification::make()->danger()->title('Could not reset the demo')->body('Please try again shortly.')->send();
                        }
                    }, 180);
                }),
            Action::make('publishBatch')
                ->label('Publish 20 changes')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->action(function (PublishExperienceHandler $publish): void {
                    app(DemoActions::class)->run('publish', function () use ($publish): void {
                        $ids = DB::connection('cms')->table('experiences')->where('status', 'published')->inRandomOrder()->limit(20)->pluck('id');

                        foreach ($ids as $id) {
                            $publish(new PublishExperience((string) $id));
                        }

                        Notification::make()->success()->title("{$ids->count()} experiences republished")
                            ->body("{$ids->count()} rows in the CMS outbox, each committed with its aggregate.")->send();
                    }, 15);
                }),
            Action::make('replay')
                ->label('Replay from outbox')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('info')
                ->modalDescription('Re-publish history from the CMS outbox with x-replay: true. Event ids are preserved, so Core\'s inbox skips what it already applied and fills only the gaps.')
                ->schema([
                    Select::make('since')
                        ->options([
                            '-5 minutes' => 'Last 5 minutes',
                            '-15 minutes' => 'Last 15 minutes',
                            '-1 hour' => 'Last hour',
                            'today' => 'Since midnight',
                            '2025-01-01' => 'Everything since launch (includes v1 events)',
                        ])
                        ->default('-15 minutes')
                        ->live()
                        ->required(),
                    Select::make('name')
                        ->label('Events')
                        ->options([
                            'catalogue.experience.*' => 'catalogue.experience.*',
                            'catalogue.experience.published' => 'catalogue.experience.published',
                            'legacy.experience.imported' => 'legacy.experience.imported',
                        ])
                        ->default('catalogue.experience.*')
                        ->live()
                        ->required()
                        ->helperText(fn (Get $get): string => $this->replayCount((string) $get('since'), (string) $get('name')).' events match.'),
                ])
                ->action(function (array $data, Outboxes $outboxes): void {
                    app(DemoActions::class)->run('replay', function () use ($data, $outboxes): void {
                        if (config('demo.enabled') && $outboxes->replayer('cms')->count(CarbonImmutable::parse((string) $data['since']), (string) $data['name']) > (int) config('demo.replay_limit')) {
                            Notification::make()->warning()->title('Choose a shorter replay window')->body('The shared demo allows up to 1,000 events per replay.')->send();

                            return;
                        }

                        $count = $outboxes->replayer('cms')->replay(CarbonImmutable::parse((string) $data['since']), (string) $data['name']);
                        Notification::make()->info()->title("Replaying {$count} events")->body('Watch the blue dots: processed fills gaps, duplicate means already applied.')->send();
                    }, 60);
                }),
            Action::make('unpark')
                ->label('Unpark all')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Move every parked message back to its queue through fellwise.requeue with a fresh retry budget. If the fault is still on, they will park again.')
                ->action(function (ParkingLot $parkingLot): void {
                    app(DemoActions::class)->run('unpark', function () use ($parkingLot): void {
                        $moved = $parkingLot->unpark('core.catalogue') + $parkingLot->unpark('cms.bookings');
                        Notification::make()->title("Unparked {$moved} message(s)")->color($moved > 0 ? 'success' : 'gray')->send();
                    }, 15);
                }),
            ActionGroup::make([
                Action::make('backfill')
                    ->label('Import TrailDesk batch (500)')
                    ->icon(Heroicon::OutlinedCircleStack)
                    ->action(function (BackfillTrailDesk $backfill): void {
                        app(DemoActions::class)->run('import', function () use ($backfill): void {
                            $result = $backfill->nextChunk(500);
                            Notification::make()->success()
                                ->title("Imported {$result['imported']} TrailDesk listings")
                                ->body("{$result['skipped']} skipped by the translator · {$result['remaining']} remaining. Each is a legacy.experience.imported.v1 event.")
                                ->send();
                        }, 30);
                    }),
                Action::make('check')
                    ->label('Run consistency check')
                    ->icon(Heroicon::OutlinedScale)
                    ->action(fn () => $this->runConsistencyCheck()),
            ])->button()->label('More')->color('gray'),
        ];
    }

    private function replayCount(string $since, string $name): string
    {
        try {
            return number_format(app(Outboxes::class)->replayer('cms')->count(CarbonImmutable::parse($since ?: '-15 minutes'), $name ?: null));
        } catch (Throwable) {
            return '—';
        }
    }
}
