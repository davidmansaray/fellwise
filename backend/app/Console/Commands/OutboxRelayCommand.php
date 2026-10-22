<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('outbox:relay {context?* : cms, core (default: all)} {--once : Drain once and exit} {--idle-ms=200 : Poll interval when idle}')]
#[Description('Publish committed outbox rows to RabbitMQ with publisher confirms')]
final class OutboxRelayCommand extends Command
{
    private bool $running = true;

    public function handle(Outboxes $outboxes): int
    {
        /** @var list<string> $contexts */
        $contexts = $this->argument('context') ?: $outboxes->contexts();
        $idle = max(50, (int) $this->option('idle-ms')) * 1000;
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });
        $this->components->info('Relaying outboxes: '.implode(', ', $contexts));

        $failed = false;

        do {
            $published = 0;

            foreach ($contexts as $context) {
                try {
                    $count = $outboxes->relay($context)->relayBatch();
                } catch (Throwable $exception) {
                    $this->components->error("[{$context}] {$exception->getMessage()}");
                    $count = 0;
                    $failed = true;
                }

                if ($count > 0) {
                    $this->line(sprintf('<fg=gray>%s</> [%s] published %d', now()->format('H:i:s.v'), $context, $count));
                }

                $published += $count;
            }

            if ($this->option('once') && ($published === 0 || $failed)) {
                break;
            }

            if ($published === 0) {
                usleep($idle);
            }
        } while ($this->running);

        return $this->option('once') && $failed ? self::FAILURE : self::SUCCESS;
    }
}
