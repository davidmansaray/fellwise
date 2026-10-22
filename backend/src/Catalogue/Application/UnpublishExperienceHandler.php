<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Shared\Application\UnitOfWork;

final readonly class UnpublishExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private UnitOfWork $unitOfWork,
    ) {}

    public function __invoke(UnpublishExperience $command): void
    {
        $this->unitOfWork->run(function () use ($command): void {
            $experience = $this->experiences->get($command->experienceId);
            $experience->unpublish($command->reason);
            $this->experiences->save($experience);
        });
    }
}
