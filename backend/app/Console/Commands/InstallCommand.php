<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Closure;
use Database\Seeders\HistoricalBookingsSeeder;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\BrokerManagementClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('fellwise:install {--force : Run without confirmation}')]
#[Description('Rebuild all schemas and seed through the real pipeline (CMS → outbox → RabbitMQ → Core)')]
final class InstallCommand extends Command
{
    public function handle(BrokerManagementClient $broker): int
    {
        if (! $this->option('force') && ! $this->confirm('This drops the cms, core and ops schemas. Continue?')) {
            return self::FAILURE;
        }

        $queues = $broker->queues();

        if ($queues === null) {
            $this->components->error('RabbitMQ management API is unreachable. Check the configured broker connection.');

            return self::FAILURE;
        }

        $this->components->task('Purging broker queues', function () use ($broker, $queues) {
            foreach (array_keys($queues) as $queue) {
                if (! $broker->purge($queue)) {
                    throw new RuntimeException("Could not purge broker queue {$queue}.");
                }
            }
        });

        foreach (['ops', 'core'] as $connection) {
            if ($this->callSilently('db:wipe', ['--database' => $connection, '--force' => true]) !== 0) {
                return self::FAILURE;
            }
        }

        $steps = [
            'Migrating and seeding the Catalogue' => fn () => $this->callSilently('migrate:fresh', ['--seed' => true, '--force' => true]),
            'Relaying the CMS outbox to RabbitMQ' => fn () => $this->callSilently('outbox:relay', ['context' => ['cms'], '--once' => true]),
            'Projecting the catalogue into Core' => fn () => $this->callSilently('messaging:consume', ['queue' => 'core.catalogue', '--exit-when-idle' => 3]),
            'Importing 18 months of booking history' => fn () => $this->callSilently('db:seed', ['--class' => HistoricalBookingsSeeder::class, '--force' => true]),
            'Checking consistency' => fn () => $this->callSilently('consistency:check'),
        ];
        foreach ($steps as $label => $step) {
            if (! $this->runStep($label, $step)) {
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->components->info('Fellwise is ready. Sign in as ops@fellwise.co.uk / fellwise, then run `composer workers`.');

        return self::SUCCESS;
    }

    private function runStep(string $label, Closure $step): bool
    {
        $successful = false;
        $this->components->task($label, function () use ($step, &$successful): bool {
            return $successful = $step() === 0;
        });

        return $successful;
    }
}
