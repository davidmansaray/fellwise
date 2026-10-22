<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Domain\Event;

use Fellwise\Shared\Domain\Messaging\DomainEvent;

final readonly class BookingConfirmed implements DomainEvent
{
    public function __construct(
        public string $bookingId,
        public string $experienceId,
        public int $partySize,
        public int $amountMinor,
        public string $currency,
        public string $departsOn,
        public string $confirmedAt,
    ) {}

    public static function eventName(): string
    {
        return 'bookings.booking.confirmed';
    }

    public static function eventVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'booking';
    }

    public function aggregateId(): string
    {
        return $this->bookingId;
    }

    public function payload(): array
    {
        return [
            'booking_id' => $this->bookingId,
            'experience_id' => $this->experienceId,
            'party_size' => $this->partySize,
            'amount_minor' => $this->amountMinor,
            'currency' => $this->currency,
            'departs_on' => $this->departsOn,
            'confirmed_at' => $this->confirmedAt,
        ];
    }
}
