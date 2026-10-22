<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Outbox;

use DateTimeImmutable;
use Fellwise\Shared\Application\Messaging\CorrelationContext;
use Fellwise\Shared\Application\Messaging\EventRecorder;
use Fellwise\Shared\Domain\Messaging\DomainEvent;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use LogicException;

/**
 * Transactional outbox. Appends the event to `outbox_messages` on the same
 * connection the aggregate was saved on, and refuses to run outside a
 * transaction: a dual write (DB commit + broker publish) is never allowed.
 */
final readonly class OutboxEventRecorder implements EventRecorder
{
    public function __construct(
        private ConnectionInterface $connection,
        private CorrelationContext $correlation,
        private string $producer,
    ) {}

    public function record(DomainEvent $event, int $aggregateVersion): Envelope
    {
        if ($this->connection->transactionLevel() === 0) {
            throw new LogicException('Events must be recorded inside the aggregate\'s transaction.');
        }

        $envelope = new Envelope(
            eventId: (string) Str::ulid(),
            eventName: $event::eventName(),
            eventVersion: $event::eventVersion(),
            occurredAt: new DateTimeImmutable,
            aggregateType: $event->aggregateType(),
            aggregateId: $event->aggregateId(),
            aggregateVersion: $aggregateVersion,
            correlationId: $this->correlation->correlationId(),
            causationId: $this->correlation->causationId(),
            producer: $this->producer,
            payload: $event->payload(),
        );

        OutboxTable::insert($this->connection, $envelope);

        return $envelope;
    }
}
