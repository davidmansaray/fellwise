<?php

declare(strict_types=1);

namespace Fellwise\Migration\Infrastructure;

use Fellwise\Catalogue\Application\ImportLegacyExperience;
use Illuminate\Support\Str;
use stdClass;

/**
 * Anti-corruption layer: TrailDesk's `tbl_listing` → the Catalogue's
 * language. All legacy quirks (area codes, "£85.00" strings, Y/N flags,
 * HTML entities in titles) are absorbed here and nowhere else.
 */
final class TrailDeskTranslator
{
    /** @var array<string, string> */
    private const array AREAS = [
        'LAKES' => 'lake-district',
        'LD' => 'lake-district',
        'SNOW' => 'snowdonia',
        'ERYRI' => 'snowdonia',
        'PEAK' => 'peak-district',
        'CAIRN' => 'cairngorms',
        'PEMB' => 'pembrokeshire',
        'DALES' => 'yorkshire-dales',
        'YD' => 'yorkshire-dales',
    ];

    public function translate(stdClass $row): ?ImportLegacyExperience
    {
        $region = self::AREAS[strtoupper(trim((string) $row->lst_area))] ?? null;
        // TrailDesk titles contain &nbsp; padding; trim() alone misses U+00A0.
        $title = (string) preg_replace('/^[\s\x{00A0}\x{2007}\x{202F}]+|[\s\x{00A0}\x{2007}\x{202F}]+$/u', '', html_entity_decode(strip_tags((string) $row->lst_name), ENT_QUOTES | ENT_HTML5));
        $priceMinor = $this->priceToMinor((string) $row->price_gbp);

        $live = strtoupper((string) $row->is_live) === 'Y';

        // A live listing at £0 would violate the Catalogue's publish rule:
        // skip it for manual review rather than abort the whole chunk.
        if ($region === null || $title === '' || Str::slug($title) === '' || $priceMinor === null || ($live && $priceMinor === 0)) {
            return null;
        }

        return new ImportLegacyExperience(
            legacyId: (string) $row->lst_id,
            title: $title,
            slug: Str::slug($title),
            regionId: $region,
            priceMinor: $priceMinor,
            live: $live,
        );
    }

    private function priceToMinor(string $price): ?int
    {
        $normalised = preg_replace('/[^0-9.]/', '', $price) ?? '';

        if ($normalised === '' || ! is_numeric($normalised)) {
            return null;
        }

        // String arithmetic, not float: "85.10" must be 8510, not 8509.
        [$pounds, $pence] = array_pad(explode('.', $normalised, 2), 2, '0');

        return ((int) $pounds * 100) + (int) str_pad(substr($pence, 0, 2), 2, '0');
    }
}
