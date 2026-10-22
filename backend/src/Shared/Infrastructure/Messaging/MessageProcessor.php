<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging;

use Fellwise\Shared\Application\Messaging\CorrelationContext;
use Fellwise\Shared\Application\Messaging\MessageHandler;
use Fellwise\Shared\Application\Messaging\UnprocessableMessage;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Inbox\Inbox;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RetryDecision;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RetryPolicy;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryOutcome;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use JsonException;
use Throwable;
use TypeError;
use ValueError;

/**
 * Transport-agnostic consume step: decode → upcast → inbox claim + handler
 * in one transaction → decide what happens to the message. The Rabbit
 * adapter only acts on the result, so this is unit-testable without a broker.
 */
final readonly class MessageProcessor
{
    /**
     * @param  array<string, array{connection: string, handlers: array<string, class-string<MessageHandler>>}>  $consumers
     */
    public function __construct(
        private array $consumers,
        private Container $container,
        private DatabaseManager $databases,
        private Upcasting\UpcasterChain $upcasters,
        private RetryPolicy $retryPolicy,
        private DeliveryLog $log,
        private FaultInjector $faults,
        private CorrelationContext $correlation,
    ) {}

    /**
     * @param  array<string, mixed>  $headers
     */
    public function process(string $queue, string $body, array $headers = []): ProcessingResult
    {
        try {
            $received = Envelope::fromJson($body);
        } catch (Throwable $exception) {
            Log::warning('Rejected malformed message; the queue dead-letters it to its parking lot.', ['queue' => $queue, 'error' => $exception->getMessage()]);

            return ProcessingResult::rejected('Malformed envelope: '.$exception->getMessage());
        }

        $retriesSoFar = (int) ($headers['x-retry-count'] ?? 0);
        $isReplay = (bool) ($headers['x-replay'] ?? false);
        $handlerClass = $this->consumers[$queue]['handlers'][$received->eventName] ?? null;

        if ($handlerClass === null) {
            return ProcessingResult::ignored($received);
        }

        [$envelope] = $this->upcasters->upcast($received);
        $connection = $this->databases->connection($this->consumers[$queue]['connection']);
        $startedAt = hrtime(true);

        try {
            $isNew = $connection->transaction(function () use ($connection, $queue, $envelope, $handlerClass, $isReplay): bool {
                $this->faults->throwIfFailing($queue);

                if (! Inbox::claim($connection, $envelope, $queue)) {
                    return false;
                }

                $this->correlation->continueFrom($envelope->correlationId, $envelope->eventId);

                /** @var MessageHandler $handler */
                $handler = $this->container->make($handlerClass);
                $handler->handle($envelope, $isReplay);

                return true;
            });
        } catch (Throwable $exception) {
            $decision = self::isPermanent($exception)
                ? RetryDecision::park($queue, $retriesSoFar)
                : $this->retryPolicy->decide($queue, $retriesSoFar);
            $this->log->record(
                $received, $queue,
                $decision->parked ? DeliveryOutcome::Parked : DeliveryOutcome::RetryScheduled,
                attempt: $retriesSoFar + 1,
                tier: $decision->tier,
                error: $exception->getMessage(),
                durationMs: $this->elapsedMs($startedAt),
                isReplay: $isReplay,
            );

            return ProcessingResult::failed($received, $decision, $exception->getMessage());
        } finally {
            $this->correlation->reset();
        }

        $this->log->record(
            $received, $queue,
            $isNew ? DeliveryOutcome::Processed : DeliveryOutcome::Duplicate,
            attempt: $retriesSoFar + 1,
            durationMs: $this->elapsedMs($startedAt),
            isReplay: $isReplay,
        );

        return $isNew ? ProcessingResult::processed($envelope) : ProcessingResult::duplicate($envelope);
    }

    /**
     * Failures that retrying cannot fix: park on the first attempt.
     */
    private static function isPermanent(Throwable $exception): bool
    {
        return $exception instanceof UnprocessableMessage
            || $exception instanceof TypeError
            || $exception instanceof ValueError
            || $exception instanceof JsonException;
    }

    private function elapsedMs(int|float $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }
}
