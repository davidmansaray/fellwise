<?php

declare(strict_types=1);

namespace Fellwise\Migration\Infrastructure;

use Fellwise\Catalogue\Application\ImportLegacyExperienceHandler;
use Fellwise\Shared\Application\UnitOfWork;
use Illuminate\Database\ConnectionInterface;

/**
 * Strangler-fig backfill. Each chunk imports listings into the Catalogue
 * (which emits `legacy.experience.imported.v1` through the normal outbox)
 * and advances the cursor in the same transaction, so a crash resumes
 * exactly where it stopped and never imports a listing twice.
 */
final readonly class BackfillTrailDesk
{
    public const string SOURCE = 'traildesk';

    public function __construct(
        private ConnectionInterface $legacy,
        private ConnectionInterface $cms,
        private UnitOfWork $unitOfWork,
        private TrailDeskTranslator $translator,
        private ImportLegacyExperienceHandler $import,
    ) {}

    /**
     * @return array{imported: int, skipped: int, remaining: int}
     */
    public function nextChunk(int $size = 500): array
    {
        return $this->unitOfWork->run(function () use ($size): array {
            $cursor = $this->cms->table('migration_cursors')->where('source', self::SOURCE)->lockForUpdate()->first();
            $lastId = (int) ($cursor->last_legacy_id ?? 0);

            $rows = $this->legacy->table('tbl_listing')
                ->where('lst_id', '>', $lastId)
                ->orderBy('lst_id')
                ->limit($size)
                ->get();

            $imported = $skipped = 0;

            foreach ($rows as $row) {
                $command = $this->translator->translate($row);

                if ($command === null) {
                    $skipped++;

                    continue;
                }

                ($this->import)($command);
                $imported++;
            }

            $total = $this->legacy->table('tbl_listing')->count();
            $newLastId = (int) ($rows->last()->lst_id ?? $lastId);

            $this->cms->table('migration_cursors')->upsert([[
                'source' => self::SOURCE,
                'last_legacy_id' => $newLastId,
                'imported' => (int) ($cursor->imported ?? 0) + $imported,
                'skipped' => (int) ($cursor->skipped ?? 0) + $skipped,
                'total' => $total,
                'created_at' => now(),
                'updated_at' => now(),
            ]], ['source'], ['last_legacy_id', 'imported', 'skipped', 'total', 'updated_at']);

            $remaining = $this->legacy->table('tbl_listing')->where('lst_id', '>', $newLastId)->count();

            return ['imported' => $imported, 'skipped' => $skipped, 'remaining' => $remaining];
        });
    }

    /**
     * @return array{imported: int, skipped: int, total: int, last_legacy_id: int}
     */
    public function progress(): array
    {
        $cursor = $this->cms->table('migration_cursors')->where('source', self::SOURCE)->first();
        $total = $cursor !== null ? (int) $cursor->total : $this->legacy->table('tbl_listing')->count();

        return [
            'imported' => (int) ($cursor->imported ?? 0),
            'skipped' => (int) ($cursor->skipped ?? 0),
            'total' => $total,
            'last_legacy_id' => (int) ($cursor->last_legacy_id ?? 0),
        ];
    }
}
