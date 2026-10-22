<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Outbox;

use DateTimeImmutable;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Row mapping for `outbox_messages`. The outbox is append-only and doubles
 * as the event log that replays read from, so rows are never deleted.
 */
final class OutboxTable
{
    public const string TABLE = 'outbox_messages';

    public static function insert(ConnectionInterface $connection, Envelope $envelope): void
    {
        $connection->table(self::TABLE)->insert([
            'id' => $envelope->eventId,
            'event_name' => $envelope->eventName,
            'event_version' => $envelope->eventVersion,
            'aggregate_type' => $envelope->aggregateType,
            'aggregate_id' => $envelope->aggregateId,
            'aggregate_version' => $envelope->aggregateVersion,
            'correlation_id' => $envelope->correlationId,
            'causation_id' => $envelope->causationId,
            'producer' => $envelope->producer,
            'payload' => json_encode($envelope->payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'occurred_at' => $envelope->occurredAt->format('Y-m-d H:i:s.u'),
        ]);
    }

    public static function toEnvelope(stdClass $row): Envelope
    {
        /** @var array<string, mixed> $payload */
        $payload = json_decode((string) $row->payload, true, 512, JSON_THROW_ON_ERROR);

        return new Envelope(
            eventId: (string) $row->id,
            eventName: (string) $row->event_name,
            eventVersion: (int) $row->event_version,
            occurredAt: new DateTimeImmutable((string) $row->occurred_at),
            aggregateType: (string) $row->aggregate_type,
            aggregateId: (string) $row->aggregate_id,
            aggregateVersion: (int) $row->aggregate_version,
            correlationId: (string) $row->correlation_id,
            causationId: $row->causation_id !== null ? (string) $row->causation_id : null,
            producer: (string) $row->producer,
            payload: $payload,
        );
    }
}
