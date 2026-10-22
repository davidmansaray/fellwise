<?php

declare(strict_types=1);

namespace Fellwise\Catalogue\Domain;

interface ExperienceRepository
{
    public function get(string $id): Experience;

    /**
     * Persists the aggregate and records its pending events in the outbox.
     */
    public function save(Experience $experience): void;

    public function slugExists(string $slug): bool;
}
