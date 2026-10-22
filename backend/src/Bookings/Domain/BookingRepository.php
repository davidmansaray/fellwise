<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Domain;

use DateTimeImmutable;

interface BookingRepository
{
    public function bookableExperience(string $experienceId): BookableExperience;

    public function slotsLeft(BookableExperience $experience, DateTimeImmutable $departsOn): int;

    /**
     * Persists the booking, updates the reporting read model and records
     * the booking's events, all in the caller's transaction.
     */
    public function add(Booking $booking, string $regionId): void;
}
