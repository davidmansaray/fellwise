<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Infrastructure\Reporting;

use Closure;

/**
 * @template TRow of array<string, mixed>
 */
final readonly class ReportResult
{
    /**
     * @param  list<TRow>  $rows
     * @param  list<string|int>  $bindings
     * @param  Closure(): list<array<string, mixed>>  $explain
     */
    public function __construct(
        public array $rows,
        public string $sql,
        public array $bindings,
        public float $elapsedMs,
        private Closure $explain,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function explain(): array
    {
        return ($this->explain)();
    }

    /**
     * The index MySQL chose for the first table in the plan.
     */
    public function indexUsed(): ?string
    {
        $plan = $this->explain();

        return isset($plan[0]['key']) ? (string) $plan[0]['key'] : null;
    }
}
