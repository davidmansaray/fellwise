<?php

declare(strict_types=1);

namespace Fellwise\Operations;

use Illuminate\Database\ConnectionInterface;

/**
 * Detects drift between the CMS and Core by comparing each side's stored,
 * indexed `sync_hash` (SHA1 of id|version|status|price). No cross-schema
 * joins and no full-row comparison. At catalogue scale (thousands) both
 * sides fit in memory; past ~1M rows switch to a chunked merge-join by id.
 */
final readonly class ConsistencyChecker
{
    public function __construct(
        private ConnectionInterface $cms,
        private ConnectionInterface $core,
        private ConnectionInterface $ops,
    ) {}

    /**
     * @return array{run_id: int, checked: int, drifted: int, duration_ms: int}
     */
    public function run(): array
    {
        $startedAt = hrtime(true);

        // Only experiences that have ever crossed the wire are expected in Core.
        // Both reads are covered by the (id, sync_hash) indexes.
        $cms = $this->cms->table('experiences')->where('version', '>', 0)->get(['id', 'version', 'sync_hash'])->keyBy('id');
        $core = $this->core->table('catalogue_experiences')->get(['experience_id', 'version', 'sync_hash'])->keyBy('experience_id');

        $drift = [];

        foreach ($cms as $id => $row) {
            $mirror = $core->get($id);

            if ($mirror === null) {
                $drift[] = ['aggregate_id' => (string) $id, 'kind' => 'missing', 'cms_version' => (int) $row->version, 'core_version' => null];
            } elseif ($mirror->sync_hash !== $row->sync_hash) {
                $drift[] = ['aggregate_id' => (string) $id, 'kind' => 'stale', 'cms_version' => (int) $row->version, 'core_version' => (int) $mirror->version];
            }
        }

        foreach ($core->diffKeys($cms) as $id => $mirror) {
            $drift[] = ['aggregate_id' => (string) $id, 'kind' => 'orphan', 'cms_version' => null, 'core_version' => (int) $mirror->version];
        }

        $durationMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        $runId = (int) $this->ops->table('consistency_runs')->insertGetId([
            'checked' => $cms->count(),
            'drifted' => count($drift),
            'duration_ms' => $durationMs,
            'ran_at' => now()->format('Y-m-d H:i:s.u'),
        ]);

        foreach (array_chunk($drift, 500) as $chunk) {
            $this->ops->table('consistency_drift')->insert(array_map(fn (array $row) => ['run_id' => $runId, ...$row], $chunk));
        }

        // Keep the last day of runs; older history adds nothing operationally.
        $this->ops->table('consistency_runs')->where('ran_at', '<', now()->subDay())->delete();

        return ['run_id' => $runId, 'checked' => $cms->count(), 'drifted' => count($drift), 'duration_ms' => $durationMs];
    }
}
