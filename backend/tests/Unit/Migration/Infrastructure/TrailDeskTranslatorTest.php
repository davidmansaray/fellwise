<?php

declare(strict_types=1);

namespace Tests\Unit\Migration\Infrastructure;

use Fellwise\Catalogue\Application\ImportLegacyExperience;
use Fellwise\Migration\Infrastructure\TrailDeskTranslator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TrailDeskTranslatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function prices(): iterable
    {
        yield 'pound sign with pence' => ['£85.00', 8500];
        yield 'one decimal place is tens of pence' => ['85.1', 8510];
        yield 'two decimal places' => ['71.62', 7162];
        yield 'whole pounds' => ['120', 12000];
        yield 'thousands separator' => ['£1,250.00', 125000];
        yield 'surrounding whitespace and currency code' => [' 42.50 GBP ', 4250];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function areas(): iterable
    {
        yield 'LAKES' => ['LAKES', 'lake-district'];
        yield 'LD' => ['LD', 'lake-district'];
        yield 'SNOW' => ['SNOW', 'snowdonia'];
        yield 'ERYRI' => ['ERYRI', 'snowdonia'];
        yield 'PEAK' => ['PEAK', 'peak-district'];
        yield 'CAIRN' => ['CAIRN', 'cairngorms'];
        yield 'PEMB' => ['PEMB', 'pembrokeshire'];
        yield 'DALES' => ['DALES', 'yorkshire-dales'];
        yield 'YD' => ['YD', 'yorkshire-dales'];
        yield 'lower case with padding' => ['  pemb ', 'pembrokeshire'];
    }

    /**
     * @return iterable<string, array{array<string, string>}>
     */
    public static function unmigratableRows(): iterable
    {
        yield 'price on application' => [['price_gbp' => 'POA']];
        yield 'blank price' => [['price_gbp' => '']];
        yield 'garbled price' => [['price_gbp' => '85.00.00']];
        yield 'area outside the catalogue (Northern Ireland)' => [['lst_area' => 'NI']];
        yield 'blank area' => [['lst_area' => '']];
        yield 'empty title' => [['lst_name' => '']];
        yield 'whitespace title' => [['lst_name' => "  \t "]];
        yield 'title that is only markup' => [['lst_name' => '<br/>']];
    }

    public function test_translates_a_clean_live_listing(): void
    {
        $command = (new TrailDeskTranslator)->translate($this->row());

        $this->assertEquals(new ImportLegacyExperience(
            legacyId: '1003',
            title: 'Wild swim: Borrowdale #3',
            slug: 'wild-swim-borrowdale-3',
            regionId: 'pembrokeshire',
            priceMinor: 8400,
            live: true,
        ), $command);
    }

    #[DataProvider('prices')]
    public function test_converts_legacy_price_strings_to_exact_minor_units(string $legacyPrice, int $expectedMinor): void
    {
        $command = (new TrailDeskTranslator)->translate($this->row(['price_gbp' => $legacyPrice]));

        $this->assertNotNull($command);
        $this->assertSame($expectedMinor, $command->priceMinor);
    }

    #[DataProvider('areas')]
    public function test_maps_legacy_area_codes_to_catalogue_regions(string $area, string $regionId): void
    {
        $command = (new TrailDeskTranslator)->translate($this->row(['lst_area' => $area]));

        $this->assertNotNull($command);
        $this->assertSame($regionId, $command->regionId);
    }

    #[DataProvider('unmigratableRows')]
    public function test_skips_rows_that_cannot_be_migrated(array $overrides): void
    {
        $this->assertNull((new TrailDeskTranslator)->translate($this->row($overrides)));
    }

    public function test_decodes_html_entities_and_strips_tags_from_titles(): void
    {
        $command = (new TrailDeskTranslator)->translate($this->row(['lst_name' => '<b>Guided walk</b> &amp; picnic &ndash; Edale']));

        $this->assertNotNull($command);
        $this->assertSame('Guided walk & picnic – Edale', $command->title);
        $this->assertSame('guided-walk-picnic-edale', $command->slug);
    }

    public function test_decodes_quote_entities(): void
    {
        $command = (new TrailDeskTranslator)->translate($this->row(['lst_name' => 'Walker&#39;s &quot;Classic&quot; Round']));

        $this->assertNotNull($command);
        $this->assertSame('Walker\'s "Classic" Round', $command->title);
    }

    public function test_only_an_upper_or_lower_case_y_flag_means_live(): void
    {
        $translator = new TrailDeskTranslator;

        $this->assertTrue($translator->translate($this->row(['is_live' => 'y']))?->live);
        $this->assertFalse($translator->translate($this->row(['is_live' => 'N']))?->live);
        $this->assertFalse($translator->translate($this->row(['is_live' => '']))?->live);
        $this->assertFalse($translator->translate($this->row(['is_live' => 'Yes']))?->live);
    }

    /**
     * Production bug: `&nbsp;` decodes to U+00A0, which trim() keeps, so a
     * blank-looking legacy title passes the empty-title guard and yields an
     * experience whose title is invisible and whose slug is ''.
     */
    public function test_skips_a_title_made_only_of_non_breaking_spaces(): void
    {
        $command = (new TrailDeskTranslator)->translate($this->row(['lst_name' => '&nbsp;&nbsp;']));

        $this->assertNull($command, sprintf('Expected the row to be skipped, got title %s and slug %s.', json_encode($command?->title), json_encode($command?->slug)));
    }

    /**
     * @param  array<string, string>  $overrides
     */
    private function row(array $overrides = []): stdClass
    {
        return (object) [
            'lst_id' => 1003,
            'lst_name' => 'Wild swim: Borrowdale #3',
            'lst_area' => 'PEMB',
            'price_gbp' => '£84.00',
            'is_live' => 'Y',
            'op_name' => 'Peak Outdoors CIC',
            'modified' => '20/02/2020 10:06',
            ...$overrides,
        ];
    }
}
