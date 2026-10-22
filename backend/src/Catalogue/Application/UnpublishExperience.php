<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

final readonly class UnpublishExperience
{
    public function __construct(public string $experienceId, public string $reason) {}
}
