<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

use Closure;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\MessageProcessor;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingStatus;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Throwable;

/**
 * AMQP adapter around MessageProcessor. Manual acks, bounded prefetch, and
 * retries by republishing to a TTL tier (with publisher confirms) BEFORE
 * acking the original, so a crash at any point loses nothing. No sleep(),
 * no requeue loops.
 */
final class RabbitConsumer
{
    private bool $shouldStop = false;

    public function __construct(
        private readonly AmqpConnectionFactory $connections,
        private readonly MessageProcessor $processor,
        private readonly MessagePublisher $publisher,
        private readonly string $retryExchange,
        private readonly string $parkingExchange,
    ) {}

    /**
     * @param  Closure(string, ProcessingStatus, ?Envelope): void|null  $onMessage
     * @param  int|null  $exitWhenIdleFor  seconds without a delivery before returning (batch/CI use)
     */
    public function consume(string $queue, int $prefetch, ?int $maxMessages = null, ?Closure $onMessage = null, ?int $exitWhenIdleFor = null): void
    {
        $this->shouldStop = false;
        $lastDeliveryAt = microtime(true);
        $channel = $this->connections->connection()->channel();
        $channel->basic_qos(0, $prefetch, false);
        $handled = 0;

        $channel->basic_consume($queue, '', false, false, false, false, function (AMQPMessage $message) use ($queue, &$handled, &$lastDeliveryAt, $maxMessages, $onMessage): void {
            $lastDeliveryAt = microtime(true);
            $headers = $message->has('application_headers') && $message->get('application_headers') instanceof AMQPTable
                ? $message->get('application_headers')->getNativeData()
                : [];

            /** @var array<string, mixed> $headers */
            $result = $this->processor->process($queue, $message->getBody(), $headers);

            match ($result->status) {
                ProcessingStatus::Processed, ProcessingStatus::Duplicate, ProcessingStatus::Ignored => $message->ack(),
                ProcessingStatus::Rejected => $message->nack(false),
                ProcessingStatus::Failed => $this->reroute($message, $headers, $result->envelope, $result->retry, (string) $result->error),
            };

            $onMessage?->__invoke($queue, $result->status, $result->envelope);

            if ($maxMessages !== null && ++$handled >= $maxMessages) {
                $this->shouldStop = true;
            }
        });

        $this->trapSignals();

        while ($channel->is_consuming() && ! $this->shouldStop) {
            try {
                $channel->wait(null, false, 1.0);
            } catch (AMQPTimeoutException) {
                // idle tick: lets us notice stop signals
            }

            if ($exitWhenIdleFor !== null && microtime(true) - $lastDeliveryAt >= $exitWhenIdleFor) {
                break;
            }
        }

        $channel->close();
    }

    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function reroute(AMQPMessage $message, array $headers, ?Envelope $envelope, ?RetryDecision $decision, string $error): void
    {
        if ($envelope === null || $decision === null) {
            $message->nack(false);

            return;
        }

        try {
            $this->publisher->publish(
                $envelope,
                [
                    'x-retry-count' => $decision->retryCount,
                    'x-last-error' => mb_substr($error, 0, 250),
                    'x-first-failed-at' => (string) ($headers['x-first-failed-at'] ?? now()->toIso8601String()),
                    'x-replay' => isset($headers['x-replay']) ? (bool) $headers['x-replay'] : null,
                ],
                $decision->parked ? $this->parkingExchange : $this->retryExchange,
                $decision->routingKey,
            );
            $this->publisher->waitForConfirms();
            $message->ack();
        } catch (Throwable) {
            // Could not reach the retry tier: let the queue's own DLX park it.
            $message->nack(false);
        }
    }

    private function trapSignals(): void
    {
        if (! function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, fn () => $this->stop());
        pcntl_signal(SIGINT, fn () => $this->stop());
    }
}
