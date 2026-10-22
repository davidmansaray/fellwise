<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Domain;

use DateTimeImmutable;
use DomainException;
use Fellwise\Bookings\Domain\Event\AvailabilityChanged;
use Fellwise\Bookings\Domain\Event\BookingConfirmed;
use Fellwise\Shared\Domain\Messaging\DomainEvent;

final class Booking
{
    /** @var list<DomainEvent> */
    private array $recorded = [];

    private function __construct(
        public readonly string $id,
        public readonly string $experienceId,
        public readonly int $partySize,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly DateTimeImmutable $departsOn,
        public readonly DateTimeImmutable $confirmedAt,
    ) {}

    /**
     * @param  int  $slotsLeftBefore  places left on that departure before this booking
     */
    public static function confirm(
        string $id,
        BookableExperience $experience,
        int $partySize,
        DateTimeImmutable $departsOn,
        DateTimeImmutable $now,
        int $slotsLeftBefore,
    ): self {
        if (! $experience->isBookable()) {
            throw new DomainException("Experience {$experience->id} is not bookable.");
        }

        if ($partySize < 1 || $partySize > $slotsLeftBefore) {
            throw new DomainException("Only {$slotsLeftBefore} place(s) left on {$departsOn->format('Y-m-d')}.");
        }

        $booking = new self($id, $experience->id, $partySize, $experience->priceMinor * $partySize, $experience->currency, $departsOn, $now);
        $booking->recorded[] = new BookingConfirmed(
            $id, $experience->id, $partySize, $booking->amountMinor, $experience->currency,
            $departsOn->format('Y-m-d'), $now->format(DATE_ATOM),
        );
        $booking->recorded[] = new AvailabilityChanged($experience->id, $departsOn->format('Y-m-d'), $slotsLeftBefore - $partySize);

        return $booking;
    }

    public function leadTimeDays(): int
    {
        return max(0, (int) $this->confirmedAt->setTime(0, 0)->diff($this->departsOn)->format('%r%a'));
    }

    /**
     * Reporting bucket: 0–6 days, 1–2 weeks, 2–4 weeks, 1–2 months, 2–3 months, 3+ months.
     */
    public function leadTimeBucket(): int
    {
        return self::bucketFor($this->leadTimeDays());
    }

    public static function bucketFor(int $days): int
    {
        return match (true) {
            $days < 7 => 0,
            $days < 14 => 1,
            $days < 30 => 2,
            $days < 60 => 3,
            $days < 90 => 4,
            default => 5,
        };
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recorded;
        $this->recorded = [];

        return $events;
    }
}
