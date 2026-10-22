<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Application;

use DateTimeImmutable;
use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Shared\Application\UnitOfWork;

final readonly class PublishExperienceHandler
{
    public function __construct(
        private ExperienceRepository $experiences,
        private UnitOfWork $unitOfWork,
    ) {}

    public function __invoke(PublishExperience $command): int
    {
        return $this->unitOfWork->run(function () use ($command): int {
            $experience = $this->experiences->get($command->experienceId);
            $experience->publish(new DateTimeImmutable);
            $this->experiences->save($experience);

            return $experience->version();
        });
    }
}
