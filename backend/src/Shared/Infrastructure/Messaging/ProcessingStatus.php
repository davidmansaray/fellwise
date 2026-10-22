<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging;

enum ProcessingStatus
{
    /** Handled and committed: ack. */
    case Processed;

    /** Already in the inbox: ack without handling. */
    case Duplicate;

    /** No handler subscribes to this event (tolerant reader): ack. */
    case Ignored;

    /** Handler threw: route to the next retry tier or the parking lot, then ack. */
    case Failed;

    /** Unreadable message: nack without requeue so the queue dead-letters it. */
    case Rejected;
}
