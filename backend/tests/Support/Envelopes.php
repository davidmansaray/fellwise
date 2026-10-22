<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Fellwise\Bookings\Domain\Event\AvailabilityChanged;
use Fellwise\Bookings\Domain\Event\BookingConfirmed;
use Fellwise\Catalogue\Domain\Event\ExperiencePublished;
use Fellwise\Catalogue\Domain\Event\ExperienceUnpublished;
use Fellwise\Catalogue\Domain\Event\LegacyExperienceImported;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Domain\Messaging\DomainEvent;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Support\Str;

/**
 * Builds wire envelopes for inbound-message tests. Current-version payloads
 * come from the real domain event classes, so they cannot drift from what
 * producers emit; only the retired v1 shape is written by hand.
 */
final class Envelopes
{
    public static function fromEvent(DomainEvent $event, int $aggregateVersion, ?string $eventId = null, string $producer = 'fellwise-cms'): Envelope
    {
        return new Envelope(
            eventId: $eventId ?? (string) Str::ulid(),
            eventName: $event::eventName(),
            eventVersion: $event::eventVersion(),
            occurredAt: new DateTimeImmutable('2026-10-08T09:00:00.000+00:00'),
            aggregateType: $event->aggregateType(),
            aggregateId: $event->aggregateId(),
            aggregateVersion: $aggregateVersion,
            correlationId: (string) Str::ulid(),
            causationId: null,
            producer: $producer,
            payload: $event->payload(),
        );
    }

    public static function experiencePublished(
        string $experienceId,
        int $aggregateVersion,
        string $title = 'Helvellyn via Striding Edge',
        int $priceMinor = 8500,
        string $regionId = 'lake-district',
    ): Envelope {
        return self::fromEvent(new ExperiencePublished(
            $experienceId,
            Str::slug($title),
            $title,
            $regionId,
            null,
            Money::gbp($priceMinor),
            new DateTimeImmutable('2026-10-08T09:00:00+00:00'),
        ), $aggregateVersion);
    }

    /**
     * The retired 2025 shape: `price` was a float in pounds and there was no guide.
     *
     * @return array<string, mixed>
     */
    public static function experiencePublishedV1Payload(string $experienceId, float $pounds = 85.1): array
    {
        return [
            'experience_id' => $experienceId,
            'slug' => 'scafell-pike-sunrise',
            'title' => 'Scafell Pike sunrise',
            'region_id' => 'lake-district',
            'price' => $pounds,
            'published_at' => '2025-06-01T06:00:00+00:00',
        ];
    }

    public static function experiencePublishedV1(string $experienceId, int $aggregateVersion, float $pounds = 85.1): Envelope
    {
        return new Envelope(
            eventId: (string) Str::ulid(),
            eventName: 'catalogue.experience.published',
            eventVersion: 1,
            occurredAt: new DateTimeImmutable('2025-06-01T06:00:00.000+00:00'),
            aggregateType: 'experience',
            aggregateId: $experienceId,
            aggregateVersion: $aggregateVersion,
            correlationId: (string) Str::ulid(),
            causationId: null,
            producer: 'fellwise-cms',
            payload: self::experiencePublishedV1Payload($experienceId, $pounds),
        );
    }

    public static function experienceUnpublished(string $experienceId, int $aggregateVersion, string $reason = 'Season ended'): Envelope
    {
        return self::fromEvent(new ExperienceUnpublished($experienceId, $reason), $aggregateVersion);
    }

    public static function legacyExperienceImported(string $experienceId, bool $live = true, int $priceMinor = 4500): Envelope
    {
        return self::fromEvent(new LegacyExperienceImported(
            $experienceId, '1001', 'kayak-tour-solva', 'Kayak tour: Solva', 'pembrokeshire', Money::gbp($priceMinor), $live,
        ), 1);
    }

    public static function bookingConfirmed(
        string $experienceId,
        int $partySize = 2,
        int $amountMinor = 17000,
        string $confirmedAt = '2026-10-08T09:30:00+00:00',
    ): Envelope {
        $bookingId = (string) Str::ulid();

        return self::fromEvent(new BookingConfirmed(
            $bookingId, $experienceId, $partySize, $amountMinor, 'GBP', '2026-11-14', $confirmedAt,
        ), 1, producer: 'fellwise-core');
    }

    public static function availabilityChanged(string $experienceId, string $date = '2026-11-14', int $remainingSlots = 3): Envelope
    {
        return self::fromEvent(new AvailabilityChanged($experienceId, $date, $remainingSlots), 1, producer: 'fellwise-core');
    }
}
