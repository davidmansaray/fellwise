<?php

declare(strict_types=1);

namespace Tests\Unit\Bookings\Domain;

use DateTimeImmutable;
use DomainException;
use Fellwise\Bookings\Domain\BookableExperience;
use Fellwise\Bookings\Domain\Booking;
use Fellwise\Bookings\Domain\Event\AvailabilityChanged;
use Fellwise\Bookings\Domain\Event\BookingConfirmed;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BookingTest extends TestCase
{
    private const string BOOKING_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9BK';

    private const string EXPERIENCE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    /**
     * @return iterable<string, array{int, int}>
     */
    public static function overbookings(): iterable
    {
        yield 'party larger than places left' => [4, 3];
        yield 'sold-out departure' => [1, 0];
        yield 'empty party' => [0, 8];
        yield 'negative party' => [-2, 8];
    }

    /**
     * @return iterable<string, array{string, string, int}>
     */
    public static function leadTimes(): iterable
    {
        yield 'a week ahead, booked mid-afternoon' => ['2026-10-08 15:45:00', '2026-10-15', 7];
        yield 'late evening the day before' => ['2026-10-08 23:30:00', '2026-10-09', 1];
        yield 'same day' => ['2026-10-08 06:00:00', '2026-10-08', 0];
        yield 'across a month boundary' => ['2026-10-25 09:00:00', '2026-11-03', 9];
        yield 'back-dated departure clamps to zero' => ['2026-10-08 09:00:00', '2026-10-01', 0];
    }

    public function test_confirming_prices_the_party_and_emits_confirmation_then_availability(): void
    {
        $now = new DateTimeImmutable('2026-10-08 09:30:00+00:00');

        $booking = Booking::confirm(self::BOOKING_ID, $this->experience(), 3, new DateTimeImmutable('2026-11-14'), $now, 5);

        $this->assertSame(25500, $booking->amountMinor);
        $this->assertSame('GBP', $booking->currency);

        $events = $booking->releaseEvents();
        $this->assertCount(2, $events);
        $this->assertInstanceOf(BookingConfirmed::class, $events[0]);
        $this->assertSame([
            'booking_id' => self::BOOKING_ID,
            'experience_id' => self::EXPERIENCE_ID,
            'party_size' => 3,
            'amount_minor' => 25500,
            'currency' => 'GBP',
            'departs_on' => '2026-11-14',
            'confirmed_at' => '2026-10-08T09:30:00+00:00',
        ], $events[0]->payload());
        $this->assertSame(self::BOOKING_ID, $events[0]->aggregateId());

        $this->assertInstanceOf(AvailabilityChanged::class, $events[1]);
        $this->assertSame(['experience_id' => self::EXPERIENCE_ID, 'date' => '2026-11-14', 'remaining_slots' => 2], $events[1]->payload());
        $this->assertSame('experience', $events[1]->aggregateType());
    }

    public function test_a_party_that_exactly_fills_the_departure_leaves_zero_places(): void
    {
        $booking = Booking::confirm(self::BOOKING_ID, $this->experience(), 4, new DateTimeImmutable('2026-11-14'), new DateTimeImmutable, 4);

        $this->assertSame(0, $booking->releaseEvents()[1]->payload()['remaining_slots']);
    }

    #[DataProvider('overbookings')]
    public function test_rejects_a_party_the_departure_cannot_take(int $partySize, int $slotsLeft): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage("Only {$slotsLeft} place(s) left on 2026-11-14.");

        Booking::confirm(self::BOOKING_ID, $this->experience(), $partySize, new DateTimeImmutable('2026-11-14'), new DateTimeImmutable, $slotsLeft);
    }

    public function test_rejects_an_experience_that_is_not_published(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Experience '.self::EXPERIENCE_ID.' is not bookable.');

        Booking::confirm(self::BOOKING_ID, $this->experience(status: 'unpublished'), 2, new DateTimeImmutable('2026-11-14'), new DateTimeImmutable, 8);
    }

    #[DataProvider('leadTimes')]
    public function test_lead_time_counts_whole_calendar_days_from_the_booking_date(string $confirmedAt, string $departsOn, int $expectedDays): void
    {
        $booking = Booking::confirm(self::BOOKING_ID, $this->experience(), 1, new DateTimeImmutable($departsOn), new DateTimeImmutable($confirmedAt), 8);

        $this->assertSame($expectedDays, $booking->leadTimeDays());
    }

    private function experience(string $status = 'published'): BookableExperience
    {
        return new BookableExperience(self::EXPERIENCE_ID, 'lake-district', 8500, 'GBP', $status);
    }
}
