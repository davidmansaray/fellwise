<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Infrastructure\Persistence;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $name
 * @property ?string $legacy_code
 */
final class RegionRecord extends Model
{
    protected $connection = 'cms';

    protected $table = 'regions';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];
}
