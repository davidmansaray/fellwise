<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

final readonly class ReviseExperience
{
    public function __construct(
        public string $experienceId,
        public string $title,
        public string $slug,
        public string $regionId,
        public ?string $guideId,
        public int $priceMinor,
    ) {}
}
