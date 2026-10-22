<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

use Fellwise\Catalogue\Domain\Experience;
use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Catalogue\Domain\Money;
use Illuminate\Support\Str;

/**
 * Runs inside the backfill's unit of work (one per chunk).
 */
final readonly class ImportLegacyExperienceHandler
{
    public function __construct(private ExperienceRepository $experiences) {}

    public function __invoke(ImportLegacyExperience $command): string
    {
        $slug = $command->slug;

        if ($this->experiences->slugExists($slug)) {
            $slug .= '-td'.$command->legacyId;
        }

        $experience = Experience::importFromLegacy(
            (string) Str::ulid(),
            $command->legacyId,
            $command->title,
            $slug,
            $command->regionId,
            Money::gbp($command->priceMinor),
            $command->live,
        );
        $this->experiences->save($experience);

        return $experience->id;
    }
}
