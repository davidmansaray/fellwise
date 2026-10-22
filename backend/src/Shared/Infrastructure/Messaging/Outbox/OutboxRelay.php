<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Outbox;

use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/**
 * Moves committed outbox rows to the broker. Rows are claimed with
 * `FOR UPDATE SKIP LOCKED` so several relays can run side by side, and a row
 * is stamped `published_at` only after the broker confirms it. A crash
 * between confirm and stamp re-publishes: consumers are idempotent.
 */
final readonly class OutboxRelay
{
    public function __construct(
        private ConnectionInterface $connection,
        private MessagePublisher $publisher,
        private DeliveryLog $log,
    ) {}

    /**
     * Publishes one batch atomically: either every row in it is confirmed by
     * the broker and stamped, or none is. A publish failure rolls the batch
     * back (rows already sent will be re-sent; consumers are idempotent) and
     * the error is recorded on the failing row outside that transaction.
     *
     * @return int number of messages published
     *
     * @throws \RuntimeException when the broker nacks or confirms time out
     */
    public function relayBatch(int $limit = 100): int
    {
        // Which row failed to publish, if any; filled inside the transaction.
        $failure = new class
        {
            public ?string $rowId = null;

            public string $error = '';
        };

        try {
            return $this->connection->transaction(function () use ($limit, $failure): int {
                $rows = $this->connection->table(OutboxTable::TABLE)
                    ->whereNull('published_at')
                    ->orderBy('id')
                    ->limit($limit)
                    ->lock('FOR UPDATE SKIP LOCKED')
                    ->get();

                if ($rows->isEmpty()) {
                    return 0;
                }

                $envelopes = [];

                foreach ($rows as $row) {
                    $envelope = OutboxTable::toEnvelope($row);

                    try {
                        $this->publisher->publish($envelope);
                    } catch (Throwable $exception) {
                        $failure->rowId = (string) $row->id;
                        $failure->error = $exception->getMessage();

                        throw $exception;
                    }

                    $envelopes[] = $envelope;
                }

                $this->publisher->waitForConfirms();

                $this->connection->table(OutboxTable::TABLE)
                    ->whereIn('id', array_map(fn ($envelope) => $envelope->eventId, $envelopes))
                    ->update([
                        'published_at' => now()->format('Y-m-d H:i:s.u'),
                        'publish_attempts' => $this->connection->raw('publish_attempts + 1'),
                        'last_error' => null,
                    ]);
                $this->log->recordMany($envelopes, 'relay', DeliveryOutcome::Published);

                return count($envelopes);
            });
        } catch (Throwable $exception) {
            if ($failure->rowId === null) {
                throw $exception;
            }

            $this->connection->table(OutboxTable::TABLE)->where('id', $failure->rowId)->update([
                'publish_attempts' => $this->connection->raw('publish_attempts + 1'),
                'last_error' => mb_substr($failure->error, 0, 500),
            ]);

            return 0;
        }
    }
}
