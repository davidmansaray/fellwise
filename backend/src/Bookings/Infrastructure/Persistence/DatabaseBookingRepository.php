<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Infrastructure\Persistence;

use DateTimeImmutable;
use DomainException;
use Fellwise\Bookings\Domain\BookableExperience;
use Fellwise\Bookings\Domain\Booking;
use Fellwise\Bookings\Domain\BookingRepository;
use Fellwise\Shared\Application\Messaging\EventRecorder;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseBookingRepository implements BookingRepository
{
    public function __construct(
        private ConnectionInterface $core,
        private EventRecorder $events,
    ) {}

    public function bookableExperience(string $experienceId): BookableExperience
    {
        $row = $this->core->table('catalogue_experiences')->where('experience_id', $experienceId)->first();

        if ($row === null) {
            throw new DomainException("Experience {$experienceId} has not reached Core's catalogue yet.");
        }

        return new BookableExperience($row->experience_id, $row->region_id, (int) $row->price_minor, $row->currency, $row->status);
    }

    public function slotsLeft(BookableExperience $experience, DateTimeImmutable $departsOn): int
    {
        $booked = (int) $this->core->table('bookings')
            ->where('experience_id', $experience->id)
            ->where('departs_on', $departsOn->format('Y-m-d'))
            ->lockForUpdate()
            ->sum('party_size');

        return max(0, $experience->maxGroupSize - $booked);
    }

    public function add(Booking $booking, string $regionId): void
    {
        $this->core->table('bookings')->insert([
            'id' => $booking->id,
            'experience_id' => $booking->experienceId,
            'party_size' => $booking->partySize,
            'amount_minor' => $booking->amountMinor,
            'currency' => $booking->currency,
            'departs_on' => $booking->departsOn->format('Y-m-d'),
            'confirmed_at' => $booking->confirmedAt->format('Y-m-d H:i:s'),
            'lead_time_days' => $booking->leadTimeDays(),
        ]);

        // Same-context read model, updated synchronously in this transaction.
        $this->core->statement(
            'INSERT INTO rm_experience_performance (day, experience_id, region_id, bookings, guests, revenue_minor)
             VALUES (?, ?, ?, 1, ?, ?) AS incoming
             ON DUPLICATE KEY UPDATE bookings = rm_experience_performance.bookings + 1,
               guests = rm_experience_performance.guests + incoming.guests,
               revenue_minor = rm_experience_performance.revenue_minor + incoming.revenue_minor',
            [$booking->confirmedAt->format('Y-m-d'), $booking->experienceId, $regionId, $booking->partySize, $booking->amountMinor],
        );

        $this->core->statement(
            'INSERT INTO rm_lead_time (day, bucket, bookings) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE bookings = rm_lead_time.bookings + 1',
            [$booking->confirmedAt->format('Y-m-d'), $booking->leadTimeBucket()],
        );

        foreach ($booking->releaseEvents() as $event) {
            $this->events->record($event, 1);
        }
    }
}
