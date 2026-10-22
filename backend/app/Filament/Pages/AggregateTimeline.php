<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Resources\Experiences\ExperienceResource;
use App\Filament\Support\EventLookup;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxTable;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\ParkingLot;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;
use stdClass;

/**
 * Aggregate forensics: every event that touched one experience, each hop
 * it took through the broker, and the CMS vs Core fingerprint side by side.
 */
final class AggregateTimeline extends Page
{
    protected string $view = 'filament.pages.aggregate-timeline';

    protected static ?string $slug = 'timeline';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $aggregate = '';

    #[Url]
    public ?string $selected = null;

    public function getTitle(): string|Htmlable
    {
        return $this->cms()->title ?? 'Aggregate timeline';
    }

    public function getSubheading(): string
    {
        return 'experience · '.$this->aggregate;
    }

    public function select(string $eventId): void
    {
        $this->selected = $eventId;
    }

    public function replayEvent(string $eventId): void
    {
        app(Outboxes::class)->replayer('cms')->replayEvents([$eventId]);
        Notification::make()->info()->title('Replayed from the outbox')->body('Watch the new attempt append to this event.')->send();
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $events = DB::connection('cms')->table(OutboxTable::TABLE)
            ->where('aggregate_id', $this->aggregate)
            ->orderByDesc('id')
            ->limit(40)
            ->get();

        $attempts = DB::connection('ops')->table('delivery_attempts')
            ->where('aggregate_id', $this->aggregate)
            ->orderBy('id')
            ->get()
            ->groupBy('event_id');

        $this->selected ??= $events->first()->id ?? null;
        $selectedRow = $events->firstWhere('id', $this->selected);

        return [
            'cms' => $this->cms(),
            'core' => DB::connection('core')->table('catalogue_experiences')->where('experience_id', $this->aggregate)->first(),
            'events' => $events,
            'attempts' => $attempts,
            'selectedEnvelope' => $selectedRow !== null ? OutboxTable::toEnvelope($selectedRow) : null,
            'selectedRow' => $selectedRow,
            'payloadHtml' => $selectedRow !== null ? EventLookup::highlightJson(OutboxTable::toEnvelope($selectedRow)->payload) : '',
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('edit')
                ->label('Edit experience')
                ->color('gray')
                ->icon(Heroicon::OutlinedPencilSquare)
                ->url(fn () => ExperienceResource::getUrl('edit', ['record' => $this->aggregate])),
            Action::make('unpark')
                ->label('Unpark this experience')
                ->color('warning')
                ->icon(Heroicon::OutlinedArrowUpTray)
                ->action(function (ParkingLot $parkingLot): void {
                    $moved = $parkingLot->unpark('core.catalogue', fn ($envelope) => $envelope->aggregateId === $this->aggregate);
                    Notification::make()->title($moved ? "Unparked {$moved} message(s)" : 'Nothing parked for this experience')->info()->send();
                }),
            Action::make('replayAll')
                ->label('Replay full history')
                ->color('info')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->requiresConfirmation()
                ->modalDescription('Re-publishes every event for this experience from the outbox, oldest first, including v1 events that Core upcasts. Core\'s version guard keeps the newest state; its inbox skips what it already applied.')
                ->action(function (Outboxes $outboxes): void {
                    $ids = [];
                    foreach (DB::connection('cms')->table(OutboxTable::TABLE)->where('aggregate_id', $this->aggregate)->orderBy('id')->pluck('id') as $id) {
                        $ids[] = (string) $id;
                    }
                    $count = $outboxes->replayer('cms')->replayEvents($ids);
                    Notification::make()->info()->title("Replaying {$count} events")->send();
                }),
        ];
    }

    private function cms(): ?stdClass
    {
        return once(fn () => DB::connection('cms')->table('experiences')->where('id', $this->aggregate)->first());
    }
}
