<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Builds the read-only TrailDesk dump (SQLite) with the quirks the real
 * one had: area codes, prices as "£85.00" strings, Y/N flags, HTML
 * entities, and a few rows that cannot be migrated.
 */
final class TrailDeskSeeder extends Seeder
{
    public function run(): void
    {
        $path = (string) config('database.connections.legacy.database');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, '');
        DB::purge('legacy');
        $legacy = DB::connection('legacy');

        $legacy->statement('CREATE TABLE tbl_listing (
            lst_id INTEGER PRIMARY KEY,
            lst_name TEXT,
            lst_area TEXT,
            price_gbp TEXT,
            is_live TEXT,
            op_name TEXT,
            modified TEXT
        )');

        $areas = ['LAKES', 'LD', 'SNOW', 'ERYRI', 'PEAK', 'CAIRN', 'PEMB', 'DALES', 'YD'];
        $kinds = ['Guided walk', 'Scramble', 'Wild swim', 'Navigation day', 'Gorge walk', 'Kayak tour', 'Coasteer', 'Bushcraft day', 'Photography walk', 'Trail run'];
        $places = ['Coniston Old Man', 'Glenridding', 'Borrowdale', 'Beddgelert', 'Capel Curig', 'Llanberis', 'Edale', 'Hathersage', 'Castleton',
            'Aviemore', 'Glenmore', 'Braemar', 'Solva', 'Newgale', 'Porthgain', 'Grassington', 'Hawes', 'Settle', 'Kettlewell', 'Ambleside'];
        $operators = ['Fell & Fjord Ltd', 'Coast2Crag', 'Northern Edge Adventures', 'Peak Outdoors CIC', 'Hebden Guides'];

        mt_srand(2014);
        $rows = [];

        for ($id = 1001; $id <= 2500; $id++) {
            $kind = $kinds[mt_rand(0, count($kinds) - 1)];
            $place = $places[mt_rand(0, count($places) - 1)];
            $price = mt_rand(30, 220);
            $roll = mt_rand(1, 100);

            $rows[] = [
                'lst_id' => $id,
                'lst_name' => $roll <= 3 ? '' : ($roll <= 10 ? "{$kind} &amp; picnic &ndash; {$place}" : "{$kind}: {$place} #".($id - 1000)),
                'lst_area' => $roll === 50 ? 'NI' : $areas[mt_rand(0, count($areas) - 1)],
                'price_gbp' => $roll === 70 ? 'POA' : ($roll % 4 === 0 ? "£{$price}.00" : "{$price}.".str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT)),
                'is_live' => mt_rand(1, 100) <= 82 ? 'Y' : 'N',
                'op_name' => $operators[mt_rand(0, count($operators) - 1)],
                'modified' => date('d/m/Y H:i', mktime(mt_rand(7, 20), mt_rand(0, 59), 0, mt_rand(1, 12), mt_rand(1, 28), mt_rand(2016, 2024)) ?: 0),
            ];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            $legacy->table('tbl_listing')->insert($chunk);
        }
    }
}
