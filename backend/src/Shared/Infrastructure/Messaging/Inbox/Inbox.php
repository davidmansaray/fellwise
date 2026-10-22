<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Inbox;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Database\ConnectionInterface;

/**
 * Idempotent receiver. The (event_id, consumer) primary key makes the
 * second delivery of any event a no-op. Must be called inside the same
 * transaction as the handler, so a failed handler releases the claim.
 */
final class Inbox
{
    public const string TABLE = 'inbox_messages';

    public static function claim(ConnectionInterface $connection, Envelope $envelope, string $consumer): bool
    {
        $inserted = $connection->table(self::TABLE)->insertOrIgnore([
            'event_id' => $envelope->eventId,
            'consumer' => $consumer,
            'event_name' => $envelope->eventName,
            'event_version' => $envelope->eventVersion,
            'aggregate_id' => $envelope->aggregateId,
            'processed_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        return $inserted === 1;
    }
}
