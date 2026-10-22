<?php

declare(strict_types=1);

namespace Fellwise\Shared\Domain\Messaging;

/**
 * A fact that happened inside an aggregate. Events are immutable, named in
 * the past tense and versioned: `{context}.{aggregate}.{past-tense}` + version.
 */
interface DomainEvent
{
    public static function eventName(): string;

    public static function eventVersion(): int;

    public function aggregateType(): string;

    public function aggregateId(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
