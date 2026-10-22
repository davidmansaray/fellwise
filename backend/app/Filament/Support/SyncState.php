<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Illuminate\Support\Facades\DB;

/**
 * Per-aggregate sync state for the panel, from the latest consistency run
 * and the newest delivery attempt in the ops schema.
 */
final class SyncState
{
    /** @var array<string, string>|null */
    private static ?array $drift = null;

    /**
     * @return array<string, string> aggregate id => drift kind
     */
    public static function drift(): array
    {
        if (self::$drift !== null) {
            return self::$drift;
        }

        $runId = DB::connection('ops')->table('consistency_runs')->max('id');

        return self::$drift = $runId === null ? [] : DB::connection('ops')->table('consistency_drift')
            ->where('run_id', $runId)
            ->pluck('kind', 'aggregate_id')
            ->map(fn (mixed $kind): string => (string) $kind)
            ->all();
    }

    public static function for(string $aggregateId, int $version): string
    {
        if ($version === 0) {
            return 'draft';
        }

        $last = DB::connection('ops')->table('delivery_attempts')
            ->where('aggregate_id', $aggregateId)
            ->whereNotIn('consumer', ['relay', 'outbox'])
            ->orderByDesc('id')
            ->value('outcome');

        return match (true) {
            $last === 'parked' => 'parked',
            $last === 'retry_scheduled' => 'retrying',
            isset(self::drift()[$aggregateId]) => 'drift',
            default => 'in_sync',
        };
    }

    public static function label(string $state): string
    {
        return match ($state) {
            'in_sync' => 'In sync',
            'retrying' => 'Retrying',
            'parked' => 'Parked',
            'drift' => 'Diverged',
            default => 'Not published',
        };
    }

    public static function color(string $state): string
    {
        return match ($state) {
            'in_sync' => 'success',
            'retrying' => 'warning',
            'parked', 'drift' => 'danger',
            default => 'gray',
        };
    }
}
