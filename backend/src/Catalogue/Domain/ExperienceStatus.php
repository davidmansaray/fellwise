<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain;

enum ExperienceStatus: string
{
    case Draft = 'draft';
    case Published = 'published';
    case Unpublished = 'unpublished';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
