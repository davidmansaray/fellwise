<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\BrokerManagementClient;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ResetDemo
{
    public function __construct(
        private FaultInjector $faults,
        private BrokerManagementClient $broker,
        private Outboxes $outboxes,
    ) {}

    public function run(): int
    {
        $queues = $this->broker->queues();
        if ($queues === null) {
            throw new RuntimeException('The broker is unavailable. Please try again shortly.');
        }

        foreach (array_keys((array) config('messaging.consumers')) as $queue) {
            $this->faults->set($queue, false);
            if (! $this->broker->purge($queue.'.parking-lot')) {
                throw new RuntimeException('Could not clear the parking lot. Please try again shortly.');
            }
        }

        // The newest event carries each aggregate's current state. Replaying
        // those ids repairs gaps while preserving inbox deduplication.
        $ids = DB::connection('cms')->table('outbox_messages')
            ->whereNotNull('published_at')->groupBy('aggregate_id')
            ->selectRaw('MAX(id) AS id')->pluck('id')->all();
        $count = 0;
        foreach (array_chunk($ids, 200) as $batch) {
            $count += $this->outboxes->replayer('cms')->replayEvents($batch);
        }
        $ids = DB::connection('core')->table('outbox_messages')->whereNotNull('published_at')->pluck('id')->all();
        foreach (array_chunk($ids, 200) as $batch) {
            $count += $this->outboxes->replayer('core')->replayEvents($batch);
        }

        return $count;
    }
}
