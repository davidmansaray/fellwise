<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Telemetry;

enum DeliveryOutcome: string
{
    case Published = 'published';
    case Processed = 'processed';
    case Duplicate = 'duplicate';
    case RetryScheduled = 'retry_scheduled';
    case Parked = 'parked';
    case Unparked = 'unparked';
    case Replayed = 'replayed';

    public function label(): string
    {
        return match ($this) {
            self::Published => 'Published',
            self::Processed => 'Processed',
            self::Duplicate => 'Duplicate skipped',
            self::RetryScheduled => 'Retry scheduled',
            self::Parked => 'Parked',
            self::Unparked => 'Unparked',
            self::Replayed => 'Replayed',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Published, self::Unparked, self::Replayed => 'info',
            self::Processed => 'success',
            self::Duplicate => 'gray',
            self::RetryScheduled => 'warning',
            self::Parked => 'danger',
        };
    }
}
