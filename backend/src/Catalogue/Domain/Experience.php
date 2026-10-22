<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain;

use DateTimeImmutable;
use Fellwise\Catalogue\Domain\Event\ExperiencePublished;
use Fellwise\Catalogue\Domain\Event\ExperienceUnpublished;
use Fellwise\Catalogue\Domain\Event\LegacyExperienceImported;
use Fellwise\Catalogue\Domain\Exception\CannotPublishExperience;
use Fellwise\Shared\Domain\Messaging\DomainEvent;

/**
 * Aggregate root for an experience listing. Plain PHP: persistence and
 * messaging are adapters. `version` counts integration events, so Core can
 * reject stale or out-of-order updates by comparing versions.
 */
final class Experience
{
    /** @var list<DomainEvent> */
    private array $recorded = [];

    public function __construct(
        public readonly string $id,
        private string $title,
        private string $slug,
        private string $regionId,
        private ?string $guideId,
        private Money $price,
        private ExperienceStatus $status,
        private int $version,
        private ?DateTimeImmutable $publishedAt = null,
        private ?string $legacyId = null,
    ) {}

    public function revise(string $title, string $slug, string $regionId, ?string $guideId, Money $price): void
    {
        if ($this->status === ExperienceStatus::Published) {
            self::assertPublishable($title, $price);
        }

        $this->title = $title;
        $this->slug = $slug;
        $this->regionId = $regionId;
        $this->guideId = $guideId;
        $this->price = $price;

        // Content changes to a live listing go out immediately.
        if ($this->status === ExperienceStatus::Published) {
            $this->publish(new DateTimeImmutable);
        }
    }

    /**
     * Publishing an already-published experience republishes its current
     * content; consumers treat it as an upsert at the new version.
     */
    public function publish(DateTimeImmutable $at): void
    {
        self::assertPublishable($this->title, $this->price);

        $this->status = ExperienceStatus::Published;
        $this->publishedAt = $at;
        $this->record(new ExperiencePublished(
            $this->id, $this->slug, $this->title, $this->regionId, $this->guideId, $this->price, $at,
        ));
    }

    public function unpublish(string $reason): void
    {
        if ($this->status !== ExperienceStatus::Published) {
            throw CannotPublishExperience::notPublished();
        }

        $this->status = ExperienceStatus::Unpublished;
        $this->record(new ExperienceUnpublished($this->id, $reason));
    }

    public static function importFromLegacy(
        string $id,
        string $legacyId,
        string $title,
        string $slug,
        string $regionId,
        Money $price,
        bool $live,
    ): self {
        if ($live && $price->amountMinor === 0) {
            throw CannotPublishExperience::missing('price');
        }

        $experience = new self(
            $id, $title, $slug, $regionId, null, $price,
            $live ? ExperienceStatus::Published : ExperienceStatus::Unpublished,
            version: 0,
            publishedAt: $live ? new DateTimeImmutable : null,
            legacyId: $legacyId,
        );
        $experience->record(new LegacyExperienceImported($id, $legacyId, $slug, $title, $regionId, $price, $live));

        return $experience;
    }

    /**
     * @return list<DomainEvent>
     */
    public function releaseEvents(): array
    {
        $events = $this->recorded;
        $this->recorded = [];

        return $events;
    }

    public function title(): string
    {
        return $this->title;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function regionId(): string
    {
        return $this->regionId;
    }

    public function guideId(): ?string
    {
        return $this->guideId;
    }

    public function price(): Money
    {
        return $this->price;
    }

    public function status(): ExperienceStatus
    {
        return $this->status;
    }

    public function version(): int
    {
        return $this->version;
    }

    public function publishedAt(): ?DateTimeImmutable
    {
        return $this->publishedAt;
    }

    public function legacyId(): ?string
    {
        return $this->legacyId;
    }

    private static function assertPublishable(string $title, Money $price): void
    {
        if (trim($title) === '') {
            throw CannotPublishExperience::missing('title');
        }

        if ($price->amountMinor === 0) {
            throw CannotPublishExperience::missing('price');
        }
    }

    private function record(DomainEvent $event): void
    {
        $this->version++;
        $this->recorded[] = $event;
    }
}
