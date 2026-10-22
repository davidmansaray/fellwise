<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Operations\ConsistencyChecker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('consistency:check {--watch : Re-run every few seconds} {--every=5}')]
#[Description('Compare CMS and Core fingerprints for every experience and record drift')]
final class ConsistencyCheckCommand extends Command
{
    private bool $running = true;

    public function handle(ConsistencyChecker $checker): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });

        do {
            $result = $checker->run();
            $this->line(sprintf(
                '<fg=gray>%s</> checked %d · %s · %dms',
                now()->format('H:i:s'), $result['checked'],
                $result['drifted'] === 0 ? '<fg=green>in sync</>' : "<fg=red>{$result['drifted']} drifted</>",
                $result['duration_ms'],
            ));

            if ($this->option('watch')) {
                sleep(max(1, (int) $this->option('every')));
            }
        } while ($this->option('watch') && $this->running);

        return self::SUCCESS;
    }
}
