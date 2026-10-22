<?php

declare(strict_types=1);

namespace Tests\Feature\Catalogue\Infrastructure\Messaging;

use DateTimeImmutable;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingStatus;
use Tests\Concerns\InteractsWithCatalogue;
use Tests\Concerns\RefreshContexts;
use Tests\Support\Envelopes;
use Tests\TestCase;

final class BookingStatsProjectorTest extends TestCase
{
    use InteractsWithCatalogue;
    use RefreshContexts;

    private const string EXPERIENCE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    public function test_a_redelivered_booking_is_counted_once(): void
    {
        $booking = Envelopes::bookingConfirmed(self::EXPERIENCE_ID);

        $first = $this->consume('cms.bookings', $booking);
        $second = $this->consume('cms.bookings', $booking);

        $this->assertSame(ProcessingStatus::Processed, $first->status);
        $this->assertSame(ProcessingStatus::Duplicate, $second->status);
        $this->assertDatabaseHas('experience_booking_stats', ['experience_id' => self::EXPERIENCE_ID, 'bookings_count' => 1], 'cms');
    }

    public function test_distinct_bookings_accumulate_and_availability_is_overwritten(): void
    {
        $this->consume('cms.bookings', Envelopes::bookingConfirmed(self::EXPERIENCE_ID));
        $this->consume('cms.bookings', Envelopes::bookingConfirmed(self::EXPERIENCE_ID));
        $this->consume('cms.bookings', Envelopes::availabilityChanged(self::EXPERIENCE_ID, '2026-11-14', 5));
        $this->consume('cms.bookings', Envelopes::availabilityChanged(self::EXPERIENCE_ID, '2026-11-14', 2));

        $this->assertDatabaseHas('experience_booking_stats', [
            'experience_id' => self::EXPERIENCE_ID, 'bookings_count' => 2, 'next_departure_on' => '2026-11-14', 'next_departure_slots' => 2,
        ], 'cms');
    }

    public function test_an_older_availability_event_delivered_late_does_not_overwrite_newer_slots(): void
    {
        $newer = $this->at(Envelopes::availabilityChanged(self::EXPERIENCE_ID, '2026-11-14', 2), '2026-10-09 10:00:05');
        $older = $this->at(Envelopes::availabilityChanged(self::EXPERIENCE_ID, '2026-11-14', 6), '2026-10-09 10:00:01');

        $this->consume('cms.bookings', $newer);
        $this->consume('cms.bookings', $older);

        $this->assertDatabaseHas('experience_booking_stats', ['experience_id' => self::EXPERIENCE_ID, 'next_departure_slots' => 2], 'cms');
    }

    private function at(Envelope $envelope, string $occurredAt): Envelope
    {
        return new Envelope(
            $envelope->eventId, $envelope->eventName, $envelope->eventVersion, new DateTimeImmutable($occurredAt),
            $envelope->aggregateType, $envelope->aggregateId, $envelope->aggregateVersion, $envelope->correlationId,
            $envelope->causationId, $envelope->producer, $envelope->payload,
        );
    }
}
