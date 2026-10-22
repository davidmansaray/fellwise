<?php

declare(strict_types=1);

namespace Tests\Fakes;

use Closure;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use RuntimeException;

/**
 * Broker double with publisher-confirm semantics: publishes are pending until
 * `waitForConfirms()`, which either confirms them all or (when told to) nacks
 * them all, exactly like a channel in confirm mode.
 */
final class InMemoryMessagePublisher implements MessagePublisher
{
    /** @var list<array{envelope: Envelope, headers: array<string, scalar|null>, exchange: string, routing_key: string}> */
    private array $pending = [];

    /** @var list<array{envelope: Envelope, headers: array<string, scalar|null>, exchange: string, routing_key: string}> */
    private array $confirmed = [];

    /** @var (Closure(Envelope): bool)|null */
    private ?Closure $failPublishWhen = null;

    private string $publishError = 'Broker connection lost';

    private ?string $confirmError = null;

    private int $publishAttempts = 0;

    public function __construct(private readonly string $eventsExchange = 'fellwise.events') {}

    public function publish(Envelope $envelope, array $headers = [], ?string $exchange = null, ?string $routingKey = null): void
    {
        $this->publishAttempts++;

        if ($this->failPublishWhen !== null && ($this->failPublishWhen)($envelope)) {
            // Like a dropped AMQP connection: unconfirmed publishes are gone.
            $this->pending = [];

            throw new RuntimeException($this->publishError);
        }

        $this->pending[] = [
            'envelope' => $envelope,
            'headers' => $headers,
            'exchange' => $exchange ?? $this->eventsExchange,
            'routing_key' => $routingKey ?? $envelope->routingKey(),
        ];
    }

    public function waitForConfirms(): void
    {
        $pending = $this->pending;
        $this->pending = [];

        if ($this->confirmError !== null && $pending !== []) {
            throw new RuntimeException($this->confirmError);
        }

        array_push($this->confirmed, ...$pending);
    }

    /**
     * Every publish throws until told otherwise.
     */
    public function failEveryPublish(string $error = 'Broker connection lost'): self
    {
        return $this->failPublishWhen(static fn (): bool => true, $error);
    }

    /**
     * @param  Closure(Envelope): bool  $predicate
     */
    public function failPublishWhen(Closure $predicate, string $error = 'Broker connection lost'): self
    {
        $this->failPublishWhen = $predicate;
        $this->publishError = $error;

        return $this;
    }

    public function nackEveryConfirm(string $error = 'Broker rejected 1 and could not route 0 message(s).'): self
    {
        $this->confirmError = $error;

        return $this;
    }

    /**
     * @return list<array{envelope: Envelope, headers: array<string, scalar|null>, exchange: string, routing_key: string}>
     */
    public function confirmed(): array
    {
        return $this->confirmed;
    }

    /**
     * @return list<string>
     */
    public function confirmedEventIds(): array
    {
        return array_map(static fn (array $message): string => $message['envelope']->eventId, $this->confirmed);
    }

    /**
     * @return list<string>
     */
    public function confirmedRoutingKeys(): array
    {
        return array_map(static fn (array $message): string => $message['routing_key'], $this->confirmed);
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function publishAttempts(): int
    {
        return $this->publishAttempts;
    }
}
