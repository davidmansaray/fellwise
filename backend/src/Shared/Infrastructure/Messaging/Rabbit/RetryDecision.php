<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

final readonly class RetryDecision
{
    private function __construct(
        public bool $parked,
        public ?string $tier,
        public string $routingKey,
        public int $retryCount,
    ) {}

    public static function retry(string $tier, string $routingKey, int $retryCount): self
    {
        return new self(false, $tier, $routingKey, $retryCount);
    }

    /**
     * Parking-lot queues are bound to the parking exchange by the name of
     * the queue they protect.
     */
    public static function park(string $queue, int $retryCount): self
    {
        return new self(true, null, $queue, $retryCount);
    }
}
