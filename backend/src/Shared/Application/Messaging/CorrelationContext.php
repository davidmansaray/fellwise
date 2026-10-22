<?php

declare(strict_types=1);

namespace Fellwise\Shared\Application\Messaging;

use Illuminate\Support\Str;

/**
 * Carries correlation/causation through a unit of work. A consumer sets it
 * from the incoming envelope, so every event recorded while handling that
 * message is caused by it and shares its correlation id.
 */
final class CorrelationContext
{
    private ?string $correlationId = null;

    private ?string $causationId = null;

    public function correlationId(): string
    {
        return $this->correlationId ??= (string) Str::ulid();
    }

    public function causationId(): ?string
    {
        return $this->causationId;
    }

    public function continueFrom(string $correlationId, string $causationId): void
    {
        $this->correlationId = $correlationId;
        $this->causationId = $causationId;
    }

    public function reset(): void
    {
        $this->correlationId = null;
        $this->causationId = null;
    }
}
