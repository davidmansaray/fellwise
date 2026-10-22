<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Fellwise\Migration\Infrastructure\BackfillTrailDesk;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('legacy:backfill {--chunk=500} {--all : Keep going until TrailDesk is exhausted}')]
#[Description('Import TrailDesk listings into the CMS through the event pipeline')]
final class LegacyBackfillCommand extends Command
{
    public function handle(BackfillTrailDesk $backfill): int
    {
        do {
            $result = $backfill->nextChunk((int) $this->option('chunk'));
            $this->components->info("Imported {$result['imported']}, skipped {$result['skipped']}, {$result['remaining']} remaining.");
        } while ($this->option('all') && $result['remaining'] > 0);

        return self::SUCCESS;
    }
}
