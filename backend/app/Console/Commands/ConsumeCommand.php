<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingStatus;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\AmqpConnectionFactory;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use PhpAmqpLib\Exception\AMQPExceptionInterface;

#[Signature('messaging:consume {queue : core.catalogue | cms.bookings} {--max= : Stop after N messages} {--exit-when-idle= : Stop after N idle seconds}')]
#[Description('Consume a queue: inbox dedupe, handler, retry tiers, parking lot')]
final class ConsumeCommand extends Command
{
    /**
     * Reconnects with capped exponential backoff when the broker connection
     * drops. Unacked deliveries are redelivered by RabbitMQ on disconnect;
     * the inbox makes that redelivery harmless.
     */
    public function handle(RabbitConsumer $consumer, AmqpConnectionFactory $connections): int
    {
        $queue = (string) $this->argument('queue');
        $config = config('messaging.consumers')[$queue] ?? null;

        if (! is_array($config)) {
            $this->components->error("No consumer is configured for [{$queue}].");

            return self::FAILURE;
        }

        $this->components->info("Consuming {$queue} (prefetch {$config['prefetch']})");
        $batchMode = $this->option('max') !== null || $this->option('exit-when-idle') !== null;
        $backoff = 1;

        while (true) {
            try {
                $this->consumeOnce($consumer, $queue, (int) $config['prefetch']);

                return self::SUCCESS;
            } catch (AMQPExceptionInterface $exception) {
                $connections->close();

                if ($batchMode) {
                    $this->components->error("Broker connection lost: {$exception->getMessage()}");

                    return self::FAILURE;
                }

                $this->components->warn("Broker connection lost ({$exception->getMessage()}); reconnecting in {$backoff}s");
                sleep($backoff);
                $backoff = min(30, $backoff * 2);
            }
        }
    }

    private function consumeOnce(RabbitConsumer $consumer, string $queue, int $prefetch): void
    {
        $consumer->consume(
            $queue,
            $prefetch,
            $this->option('max') !== null ? (int) $this->option('max') : null,
            function (string $queue, ProcessingStatus $status, ?Envelope $envelope): void {
                $colour = match ($status) {
                    ProcessingStatus::Processed => 'green',
                    ProcessingStatus::Duplicate, ProcessingStatus::Ignored => 'gray',
                    ProcessingStatus::Failed => 'yellow',
                    ProcessingStatus::Rejected => 'red',
                };
                $this->line(sprintf(
                    '<fg=gray>%s</> <fg=%s>%-9s</> %s %s',
                    now()->format('H:i:s.v'), $colour, $status->name, $envelope !== null ? $envelope->routingKey() : '—', $envelope !== null ? $envelope->aggregateId : '',
                ));
            },
            $this->option('exit-when-idle') !== null ? (int) $this->option('exit-when-idle') : null,
        );
    }
}
