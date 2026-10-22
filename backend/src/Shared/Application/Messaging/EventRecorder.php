<?php

declare(strict_types=1);

namespace Fellwise\Shared\Application\Messaging;

use Fellwise\Shared\Domain\Messaging\DomainEvent;
use Fellwise\Shared\Domain\Messaging\Envelope;

/**
 * Port: record a domain event for publication. Implementations MUST write
 * inside the caller's database transaction (transactional outbox) so the
 * state change and the event commit or roll back together.
 */
interface EventRecorder
{
    public function record(DomainEvent $event, int $aggregateVersion): Envelope;
}
