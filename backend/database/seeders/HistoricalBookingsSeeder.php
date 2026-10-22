<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 18 months of bookings imported into Core at go-live (no events: this is
 * history, not news). Seasonal, weekend-heavy, ~180k rows so the reporting
 * queries and their EXPLAIN plans are meaningful.
 */
final class HistoricalBookingsSeeder extends Seeder
{
    public function run(): void
    {
        $core = DB::connection('core');
        $experiences = $core->table('catalogue_experiences')->where('status', 'published')->get(['experience_id', 'price_minor', 'region_id']);

        if ($experiences->isEmpty()) {
            return;
        }

        mt_srand(42);
        $start = Carbon::today()->subMonths(18)->startOfMonth();
        $end = Carbon::yesterday();
        $popularity = $experiences->mapWithKeys(fn ($e) => [$e->experience_id => mt_rand(40, 160) / 100])->all();
        $stats = [];
        $batch = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $season = 0.55 + 0.45 * sin((($day->dayOfYear - 80) / 365) * 2 * M_PI);
            $weekend = $day->isWeekend() ? 1.35 : 1.0;
            $growth = 0.7 + 0.6 * ($start->diffInDays($day) / max(1, $start->diffInDays($end)));

            foreach ($experiences as $experience) {
                $expected = 7.2 * $season * $weekend * $growth * $popularity[$experience->experience_id];
                $count = (int) floor($expected) + (mt_rand(0, 99) < ($expected - floor($expected)) * 100 ? 1 : 0);

                for ($n = 0; $n < $count; $n++) {
                    $party = [1, 2, 2, 2, 3, 4, 4, 6][mt_rand(0, 7)];
                    $lead = [2, 5, 9, 14, 21, 30, 45, 60, 90, 120][mt_rand(0, 9)] + mt_rand(0, 6);
                    $confirmedAt = $day->copy()->setTime(mt_rand(6, 22), mt_rand(0, 59), mt_rand(0, 59));
                    $amount = $experience->price_minor * $party;

                    $batch[] = [
                        'id' => (string) Str::ulid($confirmedAt),
                        'experience_id' => $experience->experience_id,
                        'party_size' => $party,
                        'amount_minor' => $amount,
                        'currency' => 'GBP',
                        'departs_on' => $confirmedAt->copy()->addDays($lead)->toDateString(),
                        'confirmed_at' => $confirmedAt->toDateTimeString(),
                        'lead_time_days' => $lead,
                    ];

                    $s = &$stats[$experience->experience_id];
                    $s['bookings'] = ($s['bookings'] ?? 0) + 1;
                    $s['guests'] = ($s['guests'] ?? 0) + $party;
                    $s['revenue'] = ($s['revenue'] ?? 0) + $amount;
                    $s['last'] = $confirmedAt->toDateTimeString();
                    unset($s);
                }
            }

            if (count($batch) >= 4000) {
                $core->table('bookings')->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $core->table('bookings')->insert($batch);
        }

        // Rebuild the reporting read model from the source of truth.
        $core->table('rm_experience_performance')->truncate();
        $core->statement(
            'INSERT INTO rm_experience_performance (day, experience_id, region_id, bookings, guests, revenue_minor)
             SELECT DATE(b.confirmed_at), b.experience_id, e.region_id, COUNT(*), SUM(b.party_size), SUM(b.amount_minor)
             FROM bookings b JOIN catalogue_experiences e ON e.experience_id = b.experience_id
             GROUP BY DATE(b.confirmed_at), b.experience_id, e.region_id'
        );

        $core->table('rm_lead_time')->truncate();
        $core->statement(
            'INSERT INTO rm_lead_time (day, bucket, bookings)
             SELECT DATE(confirmed_at),
                    CASE WHEN lead_time_days < 7 THEN 0 WHEN lead_time_days < 14 THEN 1 WHEN lead_time_days < 30 THEN 2
                         WHEN lead_time_days < 60 THEN 3 WHEN lead_time_days < 90 THEN 4 ELSE 5 END,
                    COUNT(*)
             FROM bookings GROUP BY 1, 2'
        );

        // The CMS's booking counters start from the same history.
        $cms = DB::connection('cms');
        foreach ($stats as $experienceId => $s) {
            $cms->table('experience_booking_stats')->upsert([[
                'experience_id' => $experienceId,
                'bookings_count' => $s['bookings'],
                'guests_count' => $s['guests'],
                'revenue_minor' => $s['revenue'],
                'last_booked_at' => $s['last'],
                'next_departure_on' => Carbon::today()->addDays(mt_rand(2, 9))->toDateString(),
                'next_departure_slots' => mt_rand(1, 8),
                'updated_at' => now(),
            ]], ['experience_id']);
        }
    }
}
