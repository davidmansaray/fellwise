<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain\Event;

use Fellwise\Shared\Domain\Messaging\DomainEvent;

final readonly class ExperienceUnpublished implements DomainEvent
{
    public function __construct(public string $experienceId, public string $reason) {}

    public static function eventName(): string
    {
        return 'catalogue.experience.unpublished';
    }

    public static function eventVersion(): int
    {
        return 1;
    }

    public function aggregateType(): string
    {
        return 'experience';
    }

    public function aggregateId(): string
    {
        return $this->experienceId;
    }

    public function payload(): array
    {
        return ['experience_id' => $this->experienceId, 'reason' => $this->reason];
    }
}
