<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('messaging:fault {queue} {state : on|off}')]
#[Description('Make a consumer\'s handlers fail (simulated outage) to exercise retries and parking')]
final class FaultCommand extends Command
{
    public function handle(FaultInjector $faults): int
    {
        $on = $this->argument('state') === 'on';
        $faults->set((string) $this->argument('queue'), $on);
        $this->components->info(sprintf('Fault injection %s for %s.', $on ? 'ON' : 'off', $this->argument('queue')));

        return self::SUCCESS;
    }
}
