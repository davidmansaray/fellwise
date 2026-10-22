<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Application;

use DateTimeImmutable;
use Fellwise\Bookings\Domain\Booking;
use Fellwise\Bookings\Domain\BookingRepository;
use Fellwise\Shared\Application\UnitOfWork;
use Illuminate\Support\Str;

final readonly class ConfirmBookingHandler
{
    public function __construct(
        private BookingRepository $bookings,
        private UnitOfWork $unitOfWork,
    ) {}

    public function __invoke(ConfirmBooking $command): string
    {
        return $this->unitOfWork->run(function () use ($command): string {
            $experience = $this->bookings->bookableExperience($command->experienceId);
            $departsOn = new DateTimeImmutable($command->departsOn);

            $booking = Booking::confirm(
                (string) Str::ulid(),
                $experience,
                $command->partySize,
                $departsOn,
                new DateTimeImmutable,
                $this->bookings->slotsLeft($experience, $departsOn),
            );
            $this->bookings->add($booking, $experience->regionId);

            return $booking->id;
        });
    }
}
