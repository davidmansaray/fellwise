<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Outbox;

use DateTimeInterface;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;

/**
 * Re-publishes history from the outbox (our event log) with `x-replay`.
 * Event ids are preserved, so consumers' inboxes skip what they already
 * processed and only fill the gaps; handlers can suppress side effects
 * (emails, webhooks) on replays.
 */
final readonly class OutboxReplayer
{
    public function __construct(
        private ConnectionInterface $connection,
        private MessagePublisher $publisher,
        private DeliveryLog $log,
    ) {}

    /**
     * @param  list<string>  $aggregateIds
     */
    public function count(DateTimeInterface $since, ?string $eventNamePattern = null, array $aggregateIds = []): int
    {
        return $this->query($since, $eventNamePattern, $aggregateIds)->count();
    }

    /**
     * @param  list<string>  $aggregateIds
     */
    public function replay(DateTimeInterface $since, ?string $eventNamePattern = null, array $aggregateIds = []): int
    {
        $replayed = 0;

        $this->query($since, $eventNamePattern, $aggregateIds)
            ->whereNotNull('published_at')
            ->orderBy('id')
            ->chunk(200, function ($rows) use (&$replayed): void {
                $envelopes = [];

                foreach ($rows as $row) {
                    $envelope = OutboxTable::toEnvelope($row);
                    $this->publisher->publish($envelope, ['x-replay' => true]);
                    $envelopes[] = $envelope;
                }

                $this->publisher->waitForConfirms();
                $this->log->recordMany($envelopes, 'outbox', DeliveryOutcome::Replayed, isReplay: true);
                $replayed += count($envelopes);
            });

        return $replayed;
    }

    /**
     * Replay specific events by id (operator "Replay this event").
     *
     * @param  list<string>  $eventIds
     */
    public function replayEvents(array $eventIds): int
    {
        $envelopes = [];

        foreach ($this->connection->table(OutboxTable::TABLE)->whereIn('id', $eventIds)->whereNotNull('published_at')->orderBy('id')->get() as $row) {
            $envelope = OutboxTable::toEnvelope($row);
            $this->publisher->publish($envelope, ['x-replay' => true]);
            $envelopes[] = $envelope;
        }

        if ($envelopes !== []) {
            $this->publisher->waitForConfirms();
            $this->log->recordMany($envelopes, 'outbox', DeliveryOutcome::Replayed, isReplay: true);
        }

        return count($envelopes);
    }

    /**
     * @param  list<string>  $aggregateIds
     */
    private function query(DateTimeInterface $since, ?string $eventNamePattern, array $aggregateIds): Builder
    {
        return $this->connection->table(OutboxTable::TABLE)
            ->where('occurred_at', '>=', $since->format('Y-m-d H:i:s'))
            ->when($eventNamePattern, fn (Builder $query, string $pattern) => $query->where('event_name', 'like', str_replace('*', '%', $pattern)))
            ->when($aggregateIds !== [], fn (Builder $query) => $query->whereIn('aggregate_id', $aggregateIds));
    }
}
