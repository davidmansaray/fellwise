<?php

declare(strict_types=1);

namespace Fellwise\Shared\Application\Messaging;

/**
 * Converts the payload of `eventName()` at `fromVersion()` to the next
 * version. Upcasters are chained until the consumer sees the latest shape,
 * so old events in the outbox stay replayable forever.
 */
interface Upcaster
{
    public function eventName(): string;

    public function fromVersion(): int;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function upcast(array $payload): array;
}
