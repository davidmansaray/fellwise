<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Infrastructure\Persistence;

use Fellwise\Catalogue\Domain\ExperienceStatus;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * Persistence model for the Catalogue `experiences` table. The panel reads
 * it directly; contract fields are only ever written through the aggregate.
 *
 * @property string $id
 * @property string $title
 * @property string $slug
 * @property string $region_id
 * @property ?string $guide_id
 * @property ?string $summary
 * @property string $activity
 * @property string $difficulty
 * @property float $duration_hours
 * @property int $max_group_size
 * @property int $price_minor
 * @property string $currency
 * @property ExperienceStatus $status
 * @property int $version
 * @property ?Carbon $published_at
 * @property ?string $legacy_id
 * @property string $sync_hash
 * @property-read ?RegionRecord $region
 * @property-read ?GuideRecord $guide
 * @property-read ?BookingStatsRecord $stats
 */
final class ExperienceRecord extends Model
{
    use HasUlids;

    protected $connection = 'cms';

    protected $table = 'experiences';

    protected $guarded = ['sync_hash'];

    protected function casts(): array
    {
        return [
            'status' => ExperienceStatus::class,
            'price_minor' => 'integer',
            'version' => 'integer',
            'max_group_size' => 'integer',
            'duration_hours' => 'float',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RegionRecord, $this>
     */
    public function region(): BelongsTo
    {
        return $this->belongsTo(RegionRecord::class, 'region_id');
    }

    /**
     * @return BelongsTo<GuideRecord, $this>
     */
    public function guide(): BelongsTo
    {
        return $this->belongsTo(GuideRecord::class, 'guide_id');
    }

    /**
     * @return HasOne<BookingStatsRecord, $this>
     */
    public function stats(): HasOne
    {
        return $this->hasOne(BookingStatsRecord::class, 'experience_id');
    }
}
