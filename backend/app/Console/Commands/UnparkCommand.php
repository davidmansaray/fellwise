<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\ParkingLot;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('messaging:unpark {queue} {--aggregate= : Only messages for this aggregate id} {--limit=1000}')]
#[Description('Move parked messages back to their queue with a fresh retry budget')]
final class UnparkCommand extends Command
{
    public function handle(ParkingLot $parkingLot): int
    {
        $aggregate = $this->option('aggregate');
        $moved = $parkingLot->unpark(
            (string) $this->argument('queue'),
            $aggregate ? fn (Envelope $envelope) => $envelope->aggregateId === $aggregate : null,
            (int) $this->option('limit'),
        );

        $this->components->info("Unparked {$moved} message(s).");

        return self::SUCCESS;
    }
}
