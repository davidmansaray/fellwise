<?php

declare(strict_types=1);

namespace Fellwise\Bookings\Infrastructure\Reporting;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/**
 * Reporting API over Core's read models. Every query returns its timing
 * and SQL so the console can show EXPLAIN next to the chart it feeds.
 */
final readonly class PerformanceReport
{
    public function __construct(private ConnectionInterface $core) {}

    /**
     * @return ReportResult<array{region_id: string, bookings: int, guests: int, revenue_minor: int}>
     */
    public function revenueByRegion(CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        return $this->run(
            'SELECT region_id, SUM(bookings) AS bookings, SUM(guests) AS guests, SUM(revenue_minor) AS revenue_minor
             FROM rm_experience_performance
             WHERE day BETWEEN ? AND ?
             GROUP BY region_id
             ORDER BY revenue_minor DESC',
            [$from->toDateString(), $to->toDateString()],
            fn (stdClass $row) => [
                'region_id' => (string) $row->region_id,
                'bookings' => (int) $row->bookings,
                'guests' => (int) $row->guests,
                'revenue_minor' => (int) $row->revenue_minor,
            ],
        );
    }

    /**
     * @return ReportResult<array{period: string, bookings: int, revenue_minor: int}>
     */
    public function bookingsOverTime(CarbonImmutable $from, CarbonImmutable $to, string $grain = 'month'): ReportResult
    {
        $bucket = match ($grain) {
            'day' => "DATE_FORMAT(day, '%Y-%m-%d')",
            'week' => "DATE_FORMAT(day - INTERVAL WEEKDAY(day) DAY, '%Y-%m-%d')",
            default => "DATE_FORMAT(day, '%Y-%m')",
        };

        return $this->run(
            "SELECT {$bucket} AS period, SUM(bookings) AS bookings, SUM(revenue_minor) AS revenue_minor
             FROM rm_experience_performance
             WHERE day BETWEEN ? AND ?
             GROUP BY period
             ORDER BY period",
            [$from->toDateString(), $to->toDateString()],
            fn (stdClass $row) => ['period' => (string) $row->period, 'bookings' => (int) $row->bookings, 'revenue_minor' => (int) $row->revenue_minor],
        );
    }

    /**
     * @return ReportResult<array{experience_id: string, title: string, region_id: string, bookings: int, revenue_minor: int}>
     */
    public function topExperiences(CarbonImmutable $from, CarbonImmutable $to, int $limit = 8): ReportResult
    {
        return $this->run(
            'SELECT p.experience_id, e.title, e.region_id, SUM(p.bookings) AS bookings, SUM(p.revenue_minor) AS revenue_minor
             FROM rm_experience_performance p
             JOIN catalogue_experiences e ON e.experience_id = p.experience_id
             WHERE p.day BETWEEN ? AND ?
             GROUP BY p.experience_id, e.title, e.region_id
             ORDER BY revenue_minor DESC
             LIMIT '.max(1, min($limit, 50)),
            [$from->toDateString(), $to->toDateString()],
            fn (stdClass $row) => [
                'experience_id' => (string) $row->experience_id,
                'title' => (string) $row->title,
                'region_id' => (string) $row->region_id,
                'bookings' => (int) $row->bookings,
                'revenue_minor' => (int) $row->revenue_minor,
            ],
        );
    }

    /** @var list<string> bucket index => label, as assigned by Booking::bucketFor() */
    public const array LEAD_BUCKETS = ['0–6 days', '1–2 weeks', '2–4 weeks', '1–2 months', '2–3 months', '3+ months'];

    /**
     * Reads the `rm_lead_time` read model (about six rows per day), never the
     * bookings table.
     *
     * @return ReportResult<array{bucket: string, bookings: int}>
     */
    public function leadTimeHistogram(CarbonImmutable $from, CarbonImmutable $to): ReportResult
    {
        return $this->run(
            'SELECT bucket, SUM(bookings) AS bookings
             FROM rm_lead_time
             WHERE day BETWEEN ? AND ?
             GROUP BY bucket
             ORDER BY bucket',
            [$from->toDateString(), $to->toDateString()],
            fn (stdClass $row) => ['bucket' => self::LEAD_BUCKETS[(int) $row->bucket] ?? (string) $row->bucket, 'bookings' => (int) $row->bookings],
        );
    }

    /**
     * @template TRow of array<string, mixed>
     *
     * @param  list<string|int>  $bindings
     * @param  callable(stdClass): TRow  $map
     * @return ReportResult<TRow>
     */
    private function run(string $sql, array $bindings, callable $map): ReportResult
    {
        $startedAt = hrtime(true);
        $rows = [];

        foreach ($this->core->select($sql, $bindings) as $row) {
            $rows[] = $map($row);
        }

        $elapsedMs = (hrtime(true) - $startedAt) / 1_000_000;

        return new ReportResult(
            rows: $rows,
            sql: preg_replace('/\s+/', ' ', trim($sql)) ?? $sql,
            bindings: $bindings,
            elapsedMs: round($elapsedMs, 1),
            explain: fn (): array => $this->explain($sql, $bindings),
        );
    }

    /**
     * @param  list<string|int>  $bindings
     * @return list<array<string, mixed>>
     */
    private function explain(string $sql, array $bindings): array
    {
        $plan = [];

        foreach ($this->core->select('EXPLAIN '.$sql, $bindings) as $row) {
            $plan[] = get_object_vars($row);
        }

        return $plan;
    }
}
