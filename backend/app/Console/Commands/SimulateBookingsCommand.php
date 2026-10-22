<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Bookings\Application\ConfirmBooking;
use Fellwise\Bookings\Application\ConfirmBookingHandler;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('bookings:simulate {--per-minute=12 : Average bookings per minute} {--count= : Stop after N bookings}')]
#[Description('Generate live customer bookings in Core (publishes bookings.* events back to the CMS)')]
final class SimulateBookingsCommand extends Command
{
    private bool $running = true;

    public function handle(ConfirmBookingHandler $confirm): int
    {
        $this->trap([SIGTERM, SIGINT], function (): void {
            $this->running = false;
        });
        $made = 0;
        $limit = $this->option('count') !== null ? (int) $this->option('count') : null;
        $interval = (int) (60_000_000 / max(1, (int) $this->option('per-minute')));

        while ($this->running && ($limit === null || $made < $limit)) {
            $experienceId = DB::connection('core')->table('catalogue_experiences')
                ->where('status', 'published')->inRandomOrder()->value('experience_id');

            if ($experienceId !== null) {
                try {
                    $id = $confirm(new ConfirmBooking(
                        (string) $experienceId,
                        random_int(1, 4),
                        now()->addDays(random_int(120, 240))->toDateString(),
                    ));
                    $made++;
                    $this->line(sprintf('<fg=gray>%s</> booking %s for %s', now()->format('H:i:s'), $id, $experienceId));
                } catch (Throwable $exception) {
                    $this->line('<fg=yellow>skipped:</> '.$exception->getMessage());
                }
            }

            if ($limit === null || $made < $limit) {
                usleep(random_int((int) ($interval * 0.4), (int) ($interval * 1.6)));
            }
        }

        return self::SUCCESS;
    }
}
