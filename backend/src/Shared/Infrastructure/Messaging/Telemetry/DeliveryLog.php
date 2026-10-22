<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Telemetry;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Database\ConnectionInterface;

/**
 * Append-only operational trail of every hop a message takes. Lives in the
 * ops schema, outside both contexts, and is what the console reads.
 */
final readonly class DeliveryLog
{
    public const string TABLE = 'delivery_attempts';

    public function __construct(private ConnectionInterface $ops) {}

    public function record(
        Envelope $envelope,
        string $consumer,
        DeliveryOutcome $outcome,
        int $attempt = 1,
        ?string $tier = null,
        ?string $error = null,
        ?int $durationMs = null,
        bool $isReplay = false,
    ): void {
        $this->recordMany([$envelope], $consumer, $outcome, $attempt, $tier, $error, $durationMs, $isReplay);
    }

    /**
     * @param  iterable<Envelope>  $envelopes
     */
    public function recordMany(
        iterable $envelopes,
        string $consumer,
        DeliveryOutcome $outcome,
        int $attempt = 1,
        ?string $tier = null,
        ?string $error = null,
        ?int $durationMs = null,
        bool $isReplay = false,
    ): void {
        $now = now()->format('Y-m-d H:i:s.u');
        $rows = [];

        foreach ($envelopes as $envelope) {
            $rows[] = [
                'event_id' => $envelope->eventId,
                'event_name' => $envelope->routingKey(),
                'aggregate_type' => $envelope->aggregateType,
                'aggregate_id' => $envelope->aggregateId,
                'correlation_id' => $envelope->correlationId,
                'consumer' => $consumer,
                'outcome' => $outcome->value,
                'attempt' => $attempt,
                'tier' => $tier,
                'error' => $error !== null ? mb_substr($error, 0, 1000) : null,
                'duration_ms' => $durationMs,
                'is_replay' => $isReplay,
                'occurred_at' => $envelope->occurredAt->format('Y-m-d H:i:s.u'),
                'created_at' => $now,
            ];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            $this->ops->table(self::TABLE)->insert($chunk);
        }
    }
}
