<?php

declare(strict_types=1);

namespace App\Filament\Resources\Experiences\Pages;

use App\Filament\Resources\Experiences\ExperienceResource;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Filament\Resources\Pages\CreateRecord;

/**
 * New experiences start as drafts. Drafts are CMS-private, so creating one
 * emits nothing; the first Publish is the first integration event.
 */
final class CreateExperience extends CreateRecord
{
    protected static string $resource = ExperienceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return [...$data, 'status' => ExperienceStatus::Draft, 'version' => 0, 'currency' => 'GBP'];
    }
}
