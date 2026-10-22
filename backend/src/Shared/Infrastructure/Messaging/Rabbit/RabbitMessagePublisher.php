<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

use Fellwise\Shared\Domain\Messaging\Envelope;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;

/**
 * Persistent, mandatory publishes on a channel in confirm mode.
 */
final class RabbitMessagePublisher implements MessagePublisher
{
    private ?AMQPChannel $channel = null;

    private int $nacked = 0;

    private int $unroutable = 0;

    /** Publishes sent on the current channel and not yet confirmed. */
    private int $pending = 0;

    public function __construct(
        private readonly AmqpConnectionFactory $connections,
        private readonly string $eventsExchange,
        private readonly float $confirmTimeoutSeconds = 5.0,
    ) {}

    public function publish(Envelope $envelope, array $headers = [], ?string $exchange = null, ?string $routingKey = null): void
    {
        $message = new AMQPMessage($envelope->toJson(), [
            'content_type' => 'application/json',
            'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
            'message_id' => $envelope->eventId,
            'correlation_id' => $envelope->correlationId,
            'type' => $envelope->routingKey(),
            'app_id' => $envelope->producer,
            'timestamp' => $envelope->occurredAt->getTimestamp(),
            'application_headers' => new AMQPTable(array_filter([
                'x-event-version' => $envelope->eventVersion,
                'x-causation-id' => $envelope->causationId,
                ...$headers,
            ], static fn (mixed $value): bool => $value !== null)),
        ]);

        $this->channel()->basic_publish(
            $message,
            $exchange ?? $this->eventsExchange,
            $routingKey ?? $envelope->routingKey(),
            mandatory: true,
        );
        $this->pending++;
    }

    public function waitForConfirms(): void
    {
        $channel = $this->channel();
        $channel->wait_for_pending_acks_returns($this->confirmTimeoutSeconds);
        $this->pending = 0;

        if ($this->nacked > 0 || $this->unroutable > 0) {
            $nacked = $this->nacked;
            $unroutable = $this->unroutable;
            $this->nacked = $this->unroutable = 0;

            throw new RuntimeException("Broker rejected {$nacked} and could not route {$unroutable} message(s).");
        }
    }

    private function channel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        // A dropped connection closes the channel and takes its outstanding
        // confirms with it. Never let a fresh channel report "all confirmed"
        // for publishes it never saw: fail so the caller rolls back.
        if ($this->pending > 0) {
            $lost = $this->pending;
            $this->pending = 0;
            $this->channel = null;

            throw new RuntimeException("Channel closed with {$lost} unconfirmed publish(es); confirms lost.");
        }

        $channel = $this->connections->connection()->channel();
        $channel->confirm_select();
        $channel->set_nack_handler(function (): void {
            $this->nacked++;
        });
        $channel->set_return_listener(function (): void {
            $this->unroutable++;
        });

        return $this->channel = $channel;
    }
}
