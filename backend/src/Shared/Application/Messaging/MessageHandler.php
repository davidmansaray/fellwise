<?php

declare(strict_types=1);

namespace Fellwise\Shared\Application\Messaging;

use Fellwise\Shared\Domain\Messaging\Envelope;

/**
 * Port: an inbound event handler (usually a projector). Runs inside the
 * consumer's transaction, after the inbox has accepted the event id, so it
 * never sees the same event twice. Throwing schedules a retry.
 */
interface MessageHandler
{
    public function handle(Envelope $envelope, bool $isReplay): void;
}
