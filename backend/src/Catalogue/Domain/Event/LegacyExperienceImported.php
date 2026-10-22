<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain\Event;

use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Domain\Messaging\DomainEvent;

/**
 * Emitted when a TrailDesk listing is taken over by the CMS (strangler fig).
 * Carries the canonical shape plus the legacy id for traceability.
 */
final readonly class LegacyExperienceImported implements DomainEvent
{
    public function __construct(
        public string $experienceId,
        public string $legacyId,
        public string $slug,
        public string $title,
        public string $regionId,
        public Money $price,
        public bool $live,
    ) {}

    public static function eventName(): string
    {
        return 'legacy.experience.imported';
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
        return [
            'experience_id' => $this->experienceId,
            'legacy_id' => $this->legacyId,
            'slug' => $this->slug,
            'title' => $this->title,
            'region_id' => $this->regionId,
            'price' => $this->price->toArray(),
            'live' => $this->live,
        ];
    }
}
