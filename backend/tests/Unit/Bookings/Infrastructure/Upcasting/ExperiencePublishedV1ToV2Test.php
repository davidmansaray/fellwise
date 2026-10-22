<?php

declare(strict_types=1);

namespace Tests\Unit\Bookings\Infrastructure\Upcasting;

use Fellwise\Bookings\Infrastructure\Upcasting\ExperiencePublishedV1ToV2;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExperiencePublishedV1ToV2Test extends TestCase
{
    /**
     * Values whose naive `(int) ($pounds * 100)` truncation would lose a penny.
     *
     * @return iterable<string, array{mixed, int}>
     */
    public static function prices(): iterable
    {
        yield '85.1 is 8510, not 8509' => [85.1, 8510];
        yield '19.99' => [19.99, 1999];
        yield '0.29' => [0.29, 29];
        yield 'whole pounds' => [120, 12000];
        yield 'numeric string' => ['42.50', 4250];
        yield 'non-numeric string' => ['POA', 0];
    }

    #[DataProvider('prices')]
    public function test_converts_float_pounds_to_integer_minor_units(mixed $pounds, int $expectedMinor): void
    {
        $payload = (new ExperiencePublishedV1ToV2)->upcast(['experience_id' => 'exp-1', 'price' => $pounds]);

        $this->assertSame(['amount_minor' => $expectedMinor, 'currency' => 'GBP'], $payload['price_display']);
    }

    public function test_replaces_price_and_defaults_the_guide_while_keeping_other_fields(): void
    {
        $payload = (new ExperiencePublishedV1ToV2)->upcast([
            'experience_id' => 'exp-1',
            'slug' => 'scafell-pike-sunrise',
            'title' => 'Scafell Pike sunrise',
            'region_id' => 'lake-district',
            'price' => 85.1,
            'published_at' => '2025-06-01T06:00:00+00:00',
        ]);

        $this->assertSame([
            'experience_id' => 'exp-1',
            'slug' => 'scafell-pike-sunrise',
            'title' => 'Scafell Pike sunrise',
            'region_id' => 'lake-district',
            'published_at' => '2025-06-01T06:00:00+00:00',
            'guide_id' => null,
            'price_display' => ['amount_minor' => 8510, 'currency' => 'GBP'],
        ], $payload);
    }

    public function test_keeps_a_guide_when_one_was_present(): void
    {
        $payload = (new ExperiencePublishedV1ToV2)->upcast(['price' => 10, 'guide_id' => 'guide-7']);

        $this->assertSame('guide-7', $payload['guide_id']);
    }

    public function test_handles_published_v1_only(): void
    {
        $upcaster = new ExperiencePublishedV1ToV2;

        $this->assertSame('catalogue.experience.published', $upcaster->eventName());
        $this->assertSame(1, $upcaster->fromVersion());
    }
}
