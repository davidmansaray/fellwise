<?php

declare(strict_types=1);

namespace Fellwise\Shared\Domain\Messaging;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * The wire format for every integration event (CloudEvents-flavoured).
 * The body of each AMQP message is exactly `toArray()` encoded as JSON.
 */
final readonly class Envelope
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public string $eventName,
        public int $eventVersion,
        public DateTimeImmutable $occurredAt,
        public string $aggregateType,
        public string $aggregateId,
        public int $aggregateVersion,
        public string $correlationId,
        public ?string $causationId,
        public string $producer,
        public array $payload,
    ) {}

    /**
     * Routing key = versioned event name, e.g. `catalogue.experience.published.v2`.
     */
    public function routingKey(): string
    {
        return sprintf('%s.v%d', $this->eventName, $this->eventVersion);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function withPayload(int $version, array $payload): self
    {
        return new self(
            $this->eventId, $this->eventName, $version, $this->occurredAt, $this->aggregateType,
            $this->aggregateId, $this->aggregateVersion, $this->correlationId, $this->causationId,
            $this->producer, $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event_id' => $this->eventId,
            'event_name' => $this->eventName,
            'event_version' => $this->eventVersion,
            'occurred_at' => $this->occurredAt->format(DateTimeInterface::RFC3339_EXTENDED),
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'aggregate_version' => $this->aggregateVersion,
            'correlation_id' => $this->correlationId,
            'causation_id' => $this->causationId,
            'producer' => $this->producer,
            'payload' => $this->payload,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['event_id', 'event_name', 'event_version', 'occurred_at', 'aggregate_type', 'aggregate_id', 'correlation_id', 'producer', 'payload'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new InvalidArgumentException("Envelope is missing [{$key}].");
            }
        }

        if (! is_array($data['payload'])) {
            throw new InvalidArgumentException('Envelope payload must be an object.');
        }

        /** @var array<string, mixed> $payload */
        $payload = $data['payload'];

        return new self(
            eventId: (string) $data['event_id'],
            eventName: (string) $data['event_name'],
            eventVersion: (int) $data['event_version'],
            occurredAt: new DateTimeImmutable((string) $data['occurred_at']),
            aggregateType: (string) $data['aggregate_type'],
            aggregateId: (string) $data['aggregate_id'],
            aggregateVersion: (int) ($data['aggregate_version'] ?? 0),
            correlationId: (string) $data['correlation_id'],
            causationId: isset($data['causation_id']) ? (string) $data['causation_id'] : null,
            producer: (string) $data['producer'],
            payload: $payload,
        );
    }

    public static function fromJson(string $json): self
    {
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new InvalidArgumentException('Envelope JSON must decode to an object.');
        }

        /** @var array<string, mixed> $data */
        return self::fromArray($data);
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
