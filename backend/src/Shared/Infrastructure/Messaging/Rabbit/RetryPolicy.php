<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

/**
 * Pure decision: given how many retries a message has had, where does it go
 * next? Tiers map to TTL queues `{queue}.retry.{tier}` that dead-letter back
 * to the main queue; past the last tier the message is parked.
 */
final readonly class RetryPolicy
{
    /**
     * @param  list<string>  $tiers  e.g. ['5s', '15s', '45s']
     */
    public function __construct(private array $tiers) {}

    public function decide(string $queue, int $retriesSoFar): RetryDecision
    {
        if ($retriesSoFar < count($this->tiers)) {
            $tier = $this->tiers[$retriesSoFar];

            return RetryDecision::retry($tier, "{$queue}.retry.{$tier}", $retriesSoFar + 1);
        }

        return RetryDecision::park($queue, $retriesSoFar);
    }

    public function maxRetries(): int
    {
        return count($this->tiers);
    }
}
