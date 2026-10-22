<?php

declare(strict_types=1);

namespace Fellwise\Shared\Application\Messaging;

use RuntimeException;

/**
 * A message that can never succeed (contract violation, missing fields).
 * Handlers throw this to skip the retry tiers and park immediately, so a bad
 * payload costs one attempt, not 65 seconds of retries.
 */
final class UnprocessableMessage extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function requireKeys(array $payload, string $eventName, string ...$keys): void
    {
        $missing = array_values(array_filter($keys, fn (string $key): bool => data_get($payload, $key) === null));

        if ($missing !== []) {
            throw new self(sprintf('%s payload is missing %s', $eventName, implode(', ', $missing)));
        }
    }
}
