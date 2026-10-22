<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Application;

final readonly class ConfirmBooking
{
    public function __construct(
        public string $experienceId,
        public int $partySize,
        public string $departsOn,
    ) {}
}
