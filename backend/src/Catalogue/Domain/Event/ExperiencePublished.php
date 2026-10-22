<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain\Event;

use DateTimeImmutable;
use DateTimeInterface;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Domain\Messaging\DomainEvent;

/**
 * v2: price moved from a float `price` to `price_display.amount_minor`.
 * v1 events still in the outbox are upcast on consumption.
 */
final readonly class ExperiencePublished implements DomainEvent
{
    public function __construct(
        public string $experienceId,
        public string $slug,
        public string $title,
        public string $regionId,
        public ?string $guideId,
        public Money $priceDisplay,
        public DateTimeImmutable $publishedAt,
    ) {}

    public static function eventName(): string
    {
        return 'catalogue.experience.published';
    }

    public static function eventVersion(): int
    {
        return 2;
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
            'slug' => $this->slug,
            'title' => $this->title,
            'region_id' => $this->regionId,
            'guide_id' => $this->guideId,
            'price_display' => $this->priceDisplay->toArray(),
            'published_at' => $this->publishedAt->format(DateTimeInterface::ATOM),
        ];
    }
}
