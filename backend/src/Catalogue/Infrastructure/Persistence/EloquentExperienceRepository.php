<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Infrastructure\Persistence;

use Fellwise\Catalogue\Domain\Experience;
use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Application\Messaging\EventRecorder;

final readonly class EloquentExperienceRepository implements ExperienceRepository
{
    public function __construct(private EventRecorder $events) {}

    public function get(string $id): Experience
    {
        $record = ExperienceRecord::query()->lockForUpdate()->findOrFail($id);

        return new Experience(
            $record->id,
            $record->title,
            $record->slug,
            $record->region_id,
            $record->guide_id,
            new Money($record->price_minor, $record->currency),
            $record->status,
            $record->version,
            $record->published_at?->toDateTimeImmutable(),
            $record->legacy_id,
        );
    }

    public function save(Experience $experience): void
    {
        $record = ExperienceRecord::query()->find($experience->id) ?? new ExperienceRecord(['id' => $experience->id]);

        $record->fill([
            'title' => $experience->title(),
            'slug' => $experience->slug(),
            'region_id' => $experience->regionId(),
            'guide_id' => $experience->guideId(),
            'price_minor' => $experience->price()->amountMinor,
            'currency' => $experience->price()->currency,
            'status' => $experience->status(),
            'version' => $experience->version(),
            'published_at' => $experience->publishedAt(),
            'legacy_id' => $experience->legacyId(),
        ]);

        if (! $record->exists) {
            $record->activity ??= 'guided walk';
        }

        $record->save();

        // Events carry the version they produced: the first pending event
        // has version (current - pending + 1).
        $events = $experience->releaseEvents();
        $version = $experience->version() - count($events);

        foreach ($events as $event) {
            $this->events->record($event, ++$version);
        }
    }

    public function slugExists(string $slug): bool
    {
        return ExperienceRecord::query()->where('slug', $slug)->exists();
    }
}
