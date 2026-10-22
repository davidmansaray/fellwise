<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Application\UnitOfWork;

/**
 * Editorial save. Only the fields that are part of the integration
 * contract go through the aggregate; presentation-only fields (summary,
 * difficulty…) are saved by the panel directly and never cross the wire.
 */
final readonly class ReviseExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private UnitOfWork $unitOfWork,
    ) {}

    public function __invoke(ReviseExperience $command): void
    {
        $this->unitOfWork->run(function () use ($command): void {
            $experience = $this->experiences->get($command->experienceId);
            $price = Money::gbp($command->priceMinor);

            $changed = $experience->title() !== $command->title
                || $experience->slug() !== $command->slug
                || $experience->regionId() !== $command->regionId
                || $experience->guideId() !== $command->guideId
                || ! $experience->price()->equals($price);

            if (! $changed) {
                return;
            }

            $experience->revise($command->title, $command->slug, $command->regionId, $command->guideId, $price);
            $this->experiences->save($experience);
        });
    }
}
