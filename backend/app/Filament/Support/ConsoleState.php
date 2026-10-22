<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Fellwise\Migration\Infrastructure\BackfillTrailDesk;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\BrokerManagementClient;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Everything Mission Control renders, read from the broker's management
 * API and the cms/core/ops schemas. Polled once a second; `$afterId` makes
 * the telemetry feed incremental so the page animates only new hops.
 */
final readonly class ConsoleState
{
    public function __construct(
        private BrokerManagementClient $broker,
        private FaultInjector $faults,
        private BackfillTrailDesk $backfill,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(int $afterId): array
    {
        $ops = DB::connection('ops');
        $queues = $this->broker->queues();
        $depth = fn (string $queue): int => $queues === null ? 0 : (($queues[$queue]['ready'] ?? 0) + ($queues[$queue]['unacked'] ?? 0));
        $consumers = fn (string $queue): int => $queues[$queue]['consumers'] ?? 0;

        $latestId = (int) $ops->table('delivery_attempts')->max('id');
        $attempts = $ops->table('delivery_attempts')
            ->where('id', '>', $afterId === 0 ? max(0, $latestId - 30) : $afterId)
            ->orderBy('id')
            ->limit(400)
            ->get(['id', 'event_id', 'event_name', 'aggregate_id', 'correlation_id', 'consumer', 'outcome', 'attempt', 'tier', 'error', 'duration_ms', 'is_replay', 'occurred_at', 'created_at'])
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'event_id' => $row->event_id,
                'event' => $row->event_name,
                'aggregate_id' => $row->aggregate_id,
                'correlation_id' => $row->correlation_id,
                'consumer' => $row->consumer,
                'outcome' => $row->outcome,
                'attempt' => (int) $row->attempt,
                'tier' => $row->tier,
                'error' => $row->error,
                'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
                'replay' => (bool) $row->is_replay,
                'at' => substr((string) $row->created_at, 11, 12),
            ])
            ->all();

        $run = $ops->table('consistency_runs')->orderByDesc('id')->first();
        $drift = $ops->table('consistency_drift')->where('run_id', $run->id ?? 0)->limit(50)->get();
        $titles = DB::connection('cms')->table('experiences')->whereIn('id', $drift->pluck('aggregate_id'))->pluck('title', 'id');

        return [
            'broker' => $queues !== null,
            'fault' => $this->faults->isFailing('core.catalogue'),
            'cursor' => $latestId,
            'attempts' => $attempts,
            'nodes' => [
                'outbox' => (int) DB::connection('cms')->table('outbox_messages')->whereNull('published_at')->count(),
                'coreOutbox' => (int) DB::connection('core')->table('outbox_messages')->whereNull('published_at')->count(),
                'outboxTotal' => (int) DB::connection('cms')->table('outbox_messages')->count(),
                'catalogue' => $depth('core.catalogue'),
                'catalogueConsumers' => $consumers('core.catalogue'),
                'retry5' => $depth('core.catalogue.retry.5s'),
                'retry15' => $depth('core.catalogue.retry.15s'),
                'retry45' => $depth('core.catalogue.retry.45s'),
                'parking' => $depth('core.catalogue.parking-lot'),
                'cmsbookings' => $depth('cms.bookings'),
                'cmsbookingsConsumers' => $consumers('cms.bookings'),
                'cmsParking' => $depth('cms.bookings.parking-lot'),
                'inbox' => (int) DB::connection('core')->table('inbox_messages')->count(),
                'readmodel' => (int) DB::connection('core')->table('catalogue_experiences')->count(),
                'legacyInCore' => (int) DB::connection('core')->table('catalogue_experiences')->where('source', 'traildesk')->count(),
                'bookingsIn' => (int) DB::connection('cms')->table('inbox_messages')->where('event_name', 'bookings.booking.confirmed')->count(),
            ],
            'kpi' => $this->kpis(),
            'consistency' => [
                'ran_at' => $run !== null ? substr((string) $run->ran_at, 11, 8) : null,
                'checked' => $run !== null ? (int) $run->checked : 0,
                'drifted' => $run !== null ? (int) $run->drifted : 0,
                'duration_ms' => $run !== null ? (int) $run->duration_ms : 0,
                'items' => $drift->map(fn (stdClass $row): array => [
                    'id' => $row->aggregate_id,
                    'title' => $titles[$row->aggregate_id] ?? $row->aggregate_id,
                    'kind' => $row->kind,
                    'cms' => $row->cms_version,
                    'core' => $row->core_version,
                ])->values()->all(),
            ],
            'backfill' => $this->backfill->progress(),
        ];
    }

    /**
     * @return array{lag_p95: int|null, throughput: int, duplicates: int, processed: int}
     */
    private function kpis(): array
    {
        $ops = DB::connection('ops');

        // End-to-end lag: event occurred (CMS commit) → projected in Core.
        $lags = $ops->table('delivery_attempts')
            ->where('outcome', 'processed')
            ->where('is_replay', false)
            ->where('created_at', '>=', now()->subMinutes(5))
            ->whereNotNull('occurred_at')
            ->orderByDesc('id')
            ->limit(500)
            ->selectRaw('TIMESTAMPDIFF(MICROSECOND, occurred_at, created_at) / 1000 AS lag_ms')
            ->pluck('lag_ms')
            ->map(fn (mixed $lag): float => (float) $lag)
            ->sort()
            ->values();

        return [
            'lag_p95' => $lags->isEmpty() ? null : (int) round((float) $lags[(int) floor(0.95 * ($lags->count() - 1))]),
            'throughput' => (int) $ops->table('delivery_attempts')->where('outcome', 'processed')->where('created_at', '>=', now()->subMinute())->count(),
            'duplicates' => (int) $ops->table('delivery_attempts')->where('outcome', 'duplicate')->where('created_at', '>=', now()->subDay())->count(),
            'processed' => (int) $ops->table('delivery_attempts')->where('outcome', 'processed')->where('created_at', '>=', now()->subDay())->count(),
        ];
    }
}
