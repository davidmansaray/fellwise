<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Read-only view of ops.delivery_attempts for the panel's event log.
 *
 * @property int $id
 * @property string $event_id
 * @property string $event_name
 * @property string $aggregate_type
 * @property string $aggregate_id
 * @property string $correlation_id
 * @property string $consumer
 * @property string $outcome
 * @property int $attempt
 * @property ?string $tier
 * @property ?string $error
 * @property ?int $duration_ms
 * @property bool $is_replay
 * @property Carbon $created_at
 */
final class DeliveryAttempt extends Model
{
    protected $connection = 'ops';

    protected $table = 'delivery_attempts';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['is_replay' => 'boolean', 'created_at' => 'datetime', 'occurred_at' => 'datetime'];
    }
}
