<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Infrastructure\Messaging;

use Fellwise\Shared\Application\Messaging\MessageHandler;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Illuminate\Database\ConnectionInterface;

/**
 * Keeps the CMS's "booked 214 times · 3 places left" figures current from
 * Core's booking facts. Increments are safe because the inbox guarantees
 * each event is applied once.
 */
final readonly class BookingStatsProjector implements MessageHandler
{
    public function __construct(private ConnectionInterface $cms) {}

    public function handle(Envelope $envelope, bool $isReplay): void
    {
        $payload = $envelope->payload;
        $experienceId = (string) $payload['experience_id'];

        match ($envelope->eventName) {
            'bookings.booking.confirmed' => $this->cms->statement(
                'INSERT INTO experience_booking_stats (experience_id, bookings_count, guests_count, revenue_minor, last_booked_at, updated_at)
                 VALUES (?, 1, ?, ?, ?, NOW(6)) AS incoming
                 ON DUPLICATE KEY UPDATE bookings_count = experience_booking_stats.bookings_count + 1, guests_count = experience_booking_stats.guests_count + incoming.guests_count,
                   revenue_minor = experience_booking_stats.revenue_minor + incoming.revenue_minor,
                   last_booked_at = GREATEST(COALESCE(experience_booking_stats.last_booked_at, incoming.last_booked_at), incoming.last_booked_at), updated_at = NOW(6)',
                [$experienceId, (int) $payload['party_size'], (int) $payload['amount_minor'], date('Y-m-d H:i:s', strtotime((string) $payload['confirmed_at']) ?: time())],
            ),
            'bookings.availability.changed' => $this->cms->statement(
                'INSERT INTO experience_booking_stats (experience_id, next_departure_on, next_departure_slots, availability_as_of, updated_at)
                 VALUES (?, ?, ?, ?, NOW(6)) AS incoming
                 ON DUPLICATE KEY UPDATE
                   next_departure_on = IF(incoming.availability_as_of >= COALESCE(experience_booking_stats.availability_as_of, incoming.availability_as_of), incoming.next_departure_on, experience_booking_stats.next_departure_on),
                   next_departure_slots = IF(incoming.availability_as_of >= COALESCE(experience_booking_stats.availability_as_of, incoming.availability_as_of), incoming.next_departure_slots, experience_booking_stats.next_departure_slots),
                   availability_as_of = GREATEST(COALESCE(experience_booking_stats.availability_as_of, incoming.availability_as_of), incoming.availability_as_of),
                   updated_at = NOW(6)',
                [$experienceId, (string) $payload['date'], (int) $payload['remaining_slots'], $envelope->occurredAt->format('Y-m-d H:i:s.u')],
            ),
            default => null,
        };
    }
}
