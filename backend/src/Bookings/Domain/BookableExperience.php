<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Domain;

/**
 * Core's own view of a catalogue experience, built from events.
 */
final readonly class BookableExperience
{
    public function __construct(
        public string $id,
        public string $regionId,
        public int $priceMinor,
        public string $currency,
        public string $status,
        public int $maxGroupSize = 8,
    ) {}

    public function isBookable(): bool
    {
        return $this->status === 'published';
    }
}
