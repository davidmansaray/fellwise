<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('messaging:replay {context=cms} {--since= : e.g. "2026-10-08 14:02" or "-30 minutes"} {--name= : Event name pattern, e.g. catalogue.experience.*} {--aggregate=* : Aggregate ids} {--dry-run}')]
#[Description('Re-publish history from an outbox with x-replay; inboxes skip what was already applied')]
final class ReplayCommand extends Command
{
    public function handle(Outboxes $outboxes): int
    {
        $since = CarbonImmutable::parse((string) ($this->option('since') ?: '-1 hour'));
        $replayer = $outboxes->replayer((string) $this->argument('context'));
        /** @var list<string> $aggregates */
        $aggregates = (array) $this->option('aggregate');
        $name = $this->option('name') ? (string) $this->option('name') : null;

        $count = $replayer->count($since, $name, $aggregates);

        if ($this->option('dry-run')) {
            $this->components->info("{$count} event(s) since {$since->toDateTimeString()} would be replayed.");

            return self::SUCCESS;
        }

        $this->components->info('Replayed '.$replayer->replay($since, $name, $aggregates)." of {$count} event(s).");

        return self::SUCCESS;
    }
}
