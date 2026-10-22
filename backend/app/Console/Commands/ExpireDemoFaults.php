<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('demo:expire-faults')]
#[Description('Switch off expired faults in the shared demo')]
final class ExpireDemoFaults extends Command
{
    public function handle(FaultInjector $faults): int
    {
        foreach (array_keys((array) config('messaging.consumers')) as $queue) {
            $faults->isFailing($queue);
        }

        return self::SUCCESS;
    }
}
