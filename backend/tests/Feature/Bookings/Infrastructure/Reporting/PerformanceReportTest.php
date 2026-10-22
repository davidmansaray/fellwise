<?php

declare(strict_types=1);

namespace Tests\Feature\Bookings\Infrastructure\Reporting;

use Carbon\CarbonImmutable;
use Fellwise\Bookings\Infrastructure\Reporting\PerformanceReport;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\RefreshContexts;
use Tests\TestCase;

final class PerformanceReportTest extends TestCase
{
    use RefreshContexts;

    private const string HELVELLYN = '01K74ZQ9M2H3J4K5M6N7P8Q9RA';

    private const string TRYFAN = '01K74ZQ9M2H3J4K5M6N7P8Q9RB';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([[self::HELVELLYN, 'Helvellyn via Striding Edge', 'lake-district'], [self::TRYFAN, 'Tryfan North Ridge scramble', 'snowdonia']] as [$id, $title, $region]) {
            DB::connection('core')->table('catalogue_experiences')->insert([
                'experience_id' => $id, 'title' => $title, 'slug' => $id, 'region_id' => $region, 'price_minor' => 1000,
                'currency' => 'GBP', 'status' => 'published', 'version' => 1, 'source' => 'cms', 'last_event_id' => $id, 'synced_at' => now(),
            ]);
        }

        DB::connection('core')->table('rm_experience_performance')->insert([
            ['day' => '2026-09-01', 'experience_id' => self::HELVELLYN, 'region_id' => 'lake-district', 'bookings' => 3, 'guests' => 6, 'revenue_minor' => 57000],
            ['day' => '2026-09-02', 'experience_id' => self::HELVELLYN, 'region_id' => 'lake-district', 'bookings' => 2, 'guests' => 3, 'revenue_minor' => 28500],
            ['day' => '2026-09-02', 'experience_id' => self::TRYFAN, 'region_id' => 'snowdonia', 'bookings' => 4, 'guests' => 8, 'revenue_minor' => 88000],
            ['day' => '2026-10-01', 'experience_id' => self::TRYFAN, 'region_id' => 'snowdonia', 'bookings' => 9, 'guests' => 9, 'revenue_minor' => 99000],
        ]);
    }

    public function test_revenue_by_region_sums_the_read_model_within_the_range_ordered_by_revenue(): void
    {
        $result = $this->report()->revenueByRegion(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

        $this->assertSame([
            ['region_id' => 'snowdonia', 'bookings' => 4, 'guests' => 8, 'revenue_minor' => 88000],
            ['region_id' => 'lake-district', 'bookings' => 5, 'guests' => 9, 'revenue_minor' => 85500],
        ], $result->rows);
    }

    public function test_bookings_over_time_buckets_by_month(): void
    {
        $result = $this->report()->bookingsOverTime(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'));

        $this->assertSame([
            ['period' => '2026-09', 'bookings' => 9, 'revenue_minor' => 173500],
            ['period' => '2026-10', 'bookings' => 9, 'revenue_minor' => 99000],
        ], $result->rows);
    }

    public function test_top_experiences_joins_titles_and_exposes_its_plan(): void
    {
        $result = $this->report()->topExperiences(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-10-31'), 1);

        $this->assertSame('Tryfan North Ridge scramble', $result->rows[0]['title']);
        $this->assertSame(187000, $result->rows[0]['revenue_minor']);
        $this->assertNotEmpty($result->explain());
        $this->assertStringContainsString('rm_experience_performance', $result->sql);
    }

    private function report(): PerformanceReport
    {
        return $this->app->make(PerformanceReport::class);
    }
}
