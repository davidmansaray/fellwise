<?php

declare(strict_types=1);

namespace Tests\Feature\Migration\Infrastructure;

use Fellwise\Migration\Infrastructure\BackfillTrailDesk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\RefreshContexts;
use Tests\TestCase;

final class BackfillTrailDeskTest extends TestCase
{
    use RefreshContexts;

    protected function setUp(): void
    {
        parent::setUp();

        $path = (string) config('database.connections.legacy.database');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
        DB::purge('legacy');
        DB::connection('legacy')->statement('CREATE TABLE tbl_listing (lst_id INTEGER PRIMARY KEY, lst_name TEXT, lst_area TEXT, price_gbp TEXT, is_live TEXT, op_name TEXT, modified TEXT)');
        DB::connection('legacy')->table('tbl_listing')->insert([
            ['lst_id' => 1001, 'lst_name' => 'Guided walk: Grassington', 'lst_area' => 'DALES', 'price_gbp' => '£45.00', 'is_live' => 'Y', 'op_name' => 'x', 'modified' => '01/02/2020 10:00'],
            ['lst_id' => 1002, 'lst_name' => 'Kayak tour &amp; picnic', 'lst_area' => 'PEMB', 'price_gbp' => '85.1', 'is_live' => 'N', 'op_name' => 'x', 'modified' => '01/02/2020 10:00'],
            ['lst_id' => 1003, 'lst_name' => 'Gorge walk: Belfast', 'lst_area' => 'NI', 'price_gbp' => '60.00', 'is_live' => 'Y', 'op_name' => 'x', 'modified' => '01/02/2020 10:00'],
            ['lst_id' => 1004, 'lst_name' => 'Navigation day: Edale', 'lst_area' => 'PEAK', 'price_gbp' => 'POA', 'is_live' => 'Y', 'op_name' => 'x', 'modified' => '01/02/2020 10:00'],
            ['lst_id' => 1005, 'lst_name' => 'Wild swim: Solva', 'lst_area' => 'PEMB', 'price_gbp' => '£0.00', 'is_live' => 'Y', 'op_name' => 'x', 'modified' => '01/02/2020 10:00'],
            ['lst_id' => 1006, 'lst_name' => 'Scramble: Borrowdale', 'lst_area' => 'LD', 'price_gbp' => '£95.00', 'is_live' => 'Y', 'op_name' => 'x', 'modified' => '01/02/2020 10:00'],
        ]);
    }

    public function test_imports_translatable_rows_through_the_outbox_and_skips_the_rest(): void
    {
        $result = $this->backfill()->nextChunk(5);

        $this->assertSame(['imported' => 2, 'skipped' => 3, 'remaining' => 1], $result);
        $this->assertDatabaseHas('experiences', ['legacy_id' => '1001', 'title' => 'Guided walk: Grassington', 'region_id' => 'yorkshire-dales', 'price_minor' => 4500, 'status' => 'published'], 'cms');
        $this->assertDatabaseHas('experiences', ['legacy_id' => '1002', 'title' => 'Kayak tour & picnic', 'price_minor' => 8510, 'status' => 'unpublished'], 'cms');
        $this->assertSame(2, DB::connection('cms')->table('outbox_messages')->where('event_name', 'legacy.experience.imported')->whereNull('published_at')->count());
    }

    public function test_advances_the_cursor_and_resumes_where_it_stopped(): void
    {
        $this->backfill()->nextChunk(5);
        $second = $this->backfill()->nextChunk(5);
        $third = $this->backfill()->nextChunk(5);

        $this->assertSame(['imported' => 1, 'skipped' => 0, 'remaining' => 0], $second);
        $this->assertSame(['imported' => 0, 'skipped' => 0, 'remaining' => 0], $third);
        $this->assertSame(['imported' => 3, 'skipped' => 3, 'total' => 6, 'last_legacy_id' => 1006], $this->backfill()->progress());
        $this->assertSame(3, DB::connection('cms')->table('experiences')->whereNotNull('legacy_id')->count());
    }

    private function backfill(): BackfillTrailDesk
    {
        return $this->app->make(BackfillTrailDesk::class);
    }
}
