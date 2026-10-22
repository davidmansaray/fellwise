<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * Operator tooling for `{queue}.parking-lot`. Unparking republishes each
 * message to its owning queue through the requeue exchange (so no other
 * subscriber sees it twice) with a fresh retry budget, then acks it.
 */
final readonly class ParkingLot
{
    public function __construct(
        private AmqpConnectionFactory $connections,
        private MessagePublisher $publisher,
        private DeliveryLog $log,
        private string $requeueExchange,
    ) {}

    /**
     * @param  (callable(Envelope): bool)|null  $filter  only unpark matching messages; others stay parked
     * @return int messages moved back to the main queue
     */
    public function unpark(string $queue, ?callable $filter = null, int $limit = 1000): int
    {
        $channel = $this->connections->connection()->channel();
        $moved = 0;
        $keep = [];

        try {
            // Republish a batch, wait for one round of publisher confirms,
            // and only then ack the parked originals: still lossless, but one
            // broker round trip per batch instead of per message.
            while ($moved + count($keep) < $limit) {
                $batch = [];

                while (count($batch) < 100 && $moved + count($keep) + count($batch) < $limit) {
                    $message = $channel->basic_get("{$queue}.parking-lot");

                    if (! $message instanceof AMQPMessage) {
                        break;
                    }

                    try {
                        $envelope = Envelope::fromJson($message->getBody());
                    } catch (Throwable) {
                        $keep[] = $message;

                        continue;
                    }

                    if ($filter !== null && ! $filter($envelope)) {
                        $keep[] = $message;

                        continue;
                    }

                    $headers = $message->has('application_headers') && $message->get('application_headers') instanceof AMQPTable
                        ? $message->get('application_headers')->getNativeData()
                        : [];

                    $this->publisher->publish($envelope, [
                        'x-retry-count' => 0,
                        'x-unparked-at' => now()->toIso8601String(),
                        'x-replay' => isset($headers['x-replay']) ? (bool) $headers['x-replay'] : null,
                    ], $this->requeueExchange, $queue);
                    $batch[] = [$message, $envelope];
                }

                if ($batch === []) {
                    break;
                }

                $this->publisher->waitForConfirms();

                foreach ($batch as [$message]) {
                    $message->ack();
                }

                $this->log->recordMany(array_column($batch, 1), $queue, DeliveryOutcome::Unparked);
                $moved += count($batch);
            }
        } finally {
            foreach ($keep as $message) {
                $message->nack(true);
            }
            $channel->close();
        }

        return $moved;
    }
}
