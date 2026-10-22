<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $display_name
 * @property ?string $bio
 * @property ?list<string> $certifications
 * @property ?string $home_region_id
 */
final class GuideRecord extends Model
{
    use HasUlids;

    protected $connection = 'cms';

    protected $table = 'guides';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['certifications' => 'array'];
    }

    /**
     * @return BelongsTo<RegionRecord, $this>
     */
    public function homeRegion(): BelongsTo
    {
        return $this->belongsTo(RegionRecord::class, 'home_region_id');
    }

    /**
     * @return HasMany<ExperienceRecord, $this>
     */
    public function experiences(): HasMany
    {
        return $this->hasMany(ExperienceRecord::class, 'guide_id');
    }
}
