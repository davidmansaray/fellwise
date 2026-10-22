<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Filament\Support\DemoActions;
use App\Filament\Support\EventLookup;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * Stripe-style event inspector: envelope, AMQP routing, payload, every
 * delivery attempt and the correlation chain across both contexts.
 */
final class EventDetail extends Page
{
    protected string $view = 'filament.pages.event-detail';

    protected static ?string $slug = 'event';

    protected static bool $shouldRegisterNavigation = false;

    #[Url]
    public string $event = '';

    public function getTitle(): string
    {
        $found = $this->found();

        return $found !== null ? $found['envelope']->routingKey() : 'Event';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $found = $this->found();

        if ($found === null) {
            return ['found' => null];
        }

        /** @var Envelope $envelope */
        $envelope = $found['envelope'];

        return [
            'found' => $found,
            'envelope' => $envelope,
            'attempts' => EventLookup::attempts($envelope->eventId),
            'chain' => EventLookup::correlated($envelope->correlationId),
            'payloadHtml' => EventLookup::highlightJson($envelope->payload),
        ];
    }

    protected function getHeaderActions(): array
    {
        $found = $this->found();

        return [
            Action::make('timeline')
                ->label('Aggregate timeline')
                ->color('gray')
                ->icon(Heroicon::OutlinedQueueList)
                ->visible($found !== null && $found['envelope']->aggregateType === 'experience')
                ->url(fn () => AggregateTimeline::getUrl(['aggregate' => $found['envelope']->aggregateId ?? ''])),
            Action::make('replay')
                ->label('Replay this event')
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color('info')
                ->visible($found !== null)
                ->requiresConfirmation()
                ->modalDescription('Re-publishes this exact event (same event_id) with x-replay: true. If Core already applied it, its inbox skips it as a duplicate.')
                ->action(function (Outboxes $outboxes) use ($found): void {
                    if ($found === null) {
                        return;
                    }
                    app(DemoActions::class)->run('replay', function () use ($outboxes, $found): void {
                        $outboxes->replayer($found['context'])->replayEvents([$found['envelope']->eventId]);
                        Notification::make()->info()->title('Replayed from the outbox')->body('A new delivery attempt will appear below within a second.')->send();
                    }, 60);
                }),
        ];
    }

    /**
     * @return array{context: string, envelope: Envelope, row: \stdClass}|null
     */
    private function found(): ?array
    {
        return once(fn () => $this->event !== '' ? EventLookup::find($this->event) : null);
    }
}
