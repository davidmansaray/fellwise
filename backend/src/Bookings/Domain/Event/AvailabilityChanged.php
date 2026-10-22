<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Domain\Event;

use Fellwise\Shared\Domain\Messaging\DomainEvent;

final readonly class AvailabilityChanged implements DomainEvent
{
    public function __construct(
        public string $experienceId,
        public string $date,
        public int $remainingSlots,
    ) {}

    public static function eventName(): string
    {
        return 'bookings.availability.changed';
    }

    public static function eventVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        // The availability of an experience's departures; keyed by experience.
        return 'experience';
    }

    public function aggregateId(): string
    {
        return $this->experienceId;
    }

    public function payload(): array
    {
        return ['experience_id' => $this->experienceId, 'date' => $this->date, 'remaining_slots' => $this->remainingSlots];
    }
}
