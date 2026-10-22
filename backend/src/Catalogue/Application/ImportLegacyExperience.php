<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

final readonly class ImportLegacyExperience
{
    public function __construct(
        public string $legacyId,
        public string $title,
        public string $slug,
        public string $regionId,
        public int $priceMinor,
        public bool $live,
    ) {}
}
