<?php

declare(strict_types=1);

namespace Tests\Unit\Shared\Infrastructure\Messaging\Rabbit;

use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RetryPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RetryPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, string, int}>
     */
    public static function retryTiers(): iterable
    {
        yield 'first failure waits 5s' => [0, '5s', 'core.catalogue.retry.5s', 1];
        yield 'second failure waits 15s' => [1, '15s', 'core.catalogue.retry.15s', 2];
        yield 'third failure waits 45s' => [2, '45s', 'core.catalogue.retry.45s', 3];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function exhaustedRetries(): iterable
    {
        yield 'after the last tier' => [3];
        yield 'long after the last tier' => [9];
    }

    #[DataProvider('retryTiers')]
    public function test_routes_a_failure_to_the_next_ttl_tier(int $retriesSoFar, string $tier, string $routingKey, int $retryCount): void
    {
        $policy = new RetryPolicy(['5s', '15s', '45s']);

        $decision = $policy->decide('core.catalogue', $retriesSoFar);

        $this->assertFalse($decision->parked);
        $this->assertSame($tier, $decision->tier);
        $this->assertSame($routingKey, $decision->routingKey);
        $this->assertSame($retryCount, $decision->retryCount);
    }

    #[DataProvider('exhaustedRetries')]
    public function test_parks_the_message_by_queue_name_once_every_tier_is_spent(int $retriesSoFar): void
    {
        $policy = new RetryPolicy(['5s', '15s', '45s']);

        $decision = $policy->decide('cms.bookings', $retriesSoFar);

        $this->assertTrue($decision->parked);
        $this->assertNull($decision->tier);
        $this->assertSame('cms.bookings', $decision->routingKey);
        $this->assertSame($retriesSoFar, $decision->retryCount);
    }

    public function test_parks_on_the_first_failure_when_no_tiers_are_configured(): void
    {
        $policy = new RetryPolicy([]);

        $decision = $policy->decide('core.catalogue', 0);

        $this->assertTrue($decision->parked);
        $this->assertSame(0, $policy->maxRetries());
    }

    public function test_max_retries_equals_the_number_of_tiers(): void
    {
        $this->assertSame(3, (new RetryPolicy(['5s', '15s', '45s']))->maxRetries());
    }
}
