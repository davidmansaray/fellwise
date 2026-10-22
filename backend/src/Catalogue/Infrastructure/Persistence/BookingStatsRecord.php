<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Catalogue's local projection of booking facts published by Core.
 *
 * @property string $experience_id
 * @property int $bookings_count
 * @property int $guests_count
 * @property int $revenue_minor
 * @property ?int $next_departure_slots
 * @property ?Carbon $next_departure_on
 * @property ?Carbon $last_booked_at
 */
final class BookingStatsRecord extends Model
{
    protected $connection = 'cms';

    protected $table = 'experience_booking_stats';

    protected $primaryKey = 'experience_id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['next_departure_on' => 'date', 'last_booked_at' => 'datetime'];
    }
}
