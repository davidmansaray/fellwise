<?php

declare(strict_types=1);

namespace Tests\Unit\Contract;

use DateTimeImmutable;
use Fellwise\Bookings\Domain\BookableExperience;
use Fellwise\Bookings\Domain\Booking;
use Fellwise\Bookings\Infrastructure\Upcasting\ExperiencePublishedV1ToV2;
use Fellwise\Catalogue\Domain\Experience;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Domain\Messaging\DomainEvent;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Upcasting\UpcasterChain;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\EventSchemas;

/**
 * Consumer-driven contract: payloads produced by the real domain classes
 * must match the JSON Schemas published in docs/events, and the schemas must
 * be strict enough to reject the mistakes they exist to catch.
 */
final class EventSchemaContractTest extends TestCase
{
    private const string EXPERIENCE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    private const string GUIDE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9GD';

    private const string BOOKING_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9BK';

    /**
     * @return iterable<string, array{DomainEvent}>
     */
    public static function producedEvents(): iterable
    {
        $draft = new Experience(self::EXPERIENCE_ID, 'Helvellyn via Striding Edge', 'helvellyn-via-striding-edge', 'lake-district', self::GUIDE_ID, Money::gbp(8500), ExperienceStatus::Draft, 0);
        $draft->publish(new DateTimeImmutable('2026-10-08T10:00:00+00:00'));
        $draft->revise('Helvellyn at dawn', 'helvellyn-at-dawn', 'lake-district', null, Money::gbp(9900));
        $draft->unpublish('Guide on leave');
        [$published, $republished, $unpublished] = $draft->releaseEvents();

        [$imported] = Experience::importFromLegacy(self::EXPERIENCE_ID, '1003', 'Wild swim: Borrowdale', 'wild-swim-borrowdale', 'lake-district', Money::gbp(8400), false)->releaseEvents();

        $booking = Booking::confirm(
            self::BOOKING_ID,
            new BookableExperience(self::EXPERIENCE_ID, 'lake-district', 8500, 'GBP', 'published'),
            2,
            new DateTimeImmutable('2026-11-14'),
            new DateTimeImmutable('2026-10-08T09:30:00+01:00'),
            8,
        );
        [$confirmed, $availability] = $booking->releaseEvents();

        yield 'catalogue.experience.published.v2 (first publish, with guide)' => [$published];
        yield 'catalogue.experience.published.v2 (live revision, no guide)' => [$republished];
        yield 'catalogue.experience.unpublished.v1' => [$unpublished];
        yield 'legacy.experience.imported.v1' => [$imported];
        yield 'bookings.booking.confirmed.v1' => [$confirmed];
        yield 'bookings.availability.changed.v1' => [$availability];
    }

    /**
     * Each case breaks one rule the schema must enforce.
     *
     * @return iterable<string, array{string, array<string, mixed>}>
     */
    public static function invalidPayloads(): iterable
    {
        $published = [
            'experience_id' => self::EXPERIENCE_ID, 'slug' => 'helvellyn', 'title' => 'Helvellyn', 'region_id' => 'lake-district',
            'guide_id' => null, 'price_display' => ['amount_minor' => 8500, 'currency' => 'GBP'], 'published_at' => '2026-10-08T10:00:00+00:00',
        ];

        yield 'v2 price as float pounds' => ['catalogue.experience.published.v2', [...$published, 'price_display' => ['amount_minor' => 85.1, 'currency' => 'GBP']]];
        yield 'v2 still carrying the v1 price field' => ['catalogue.experience.published.v2', [...$published, 'price' => 85.1]];
        yield 'v2 missing guide_id' => ['catalogue.experience.published.v2', array_diff_key($published, ['guide_id' => true])];
        yield 'v2 lower-case currency' => ['catalogue.experience.published.v2', [...$published, 'price_display' => ['amount_minor' => 8500, 'currency' => 'gbp']]];
        yield 'v2 non-ULID experience id' => ['catalogue.experience.published.v2', [...$published, 'experience_id' => 'exp-1']];
        yield 'v2 empty slug' => ['catalogue.experience.published.v2', [...$published, 'slug' => '']];
        yield 'v2 invisible title' => ['catalogue.experience.published.v2', [...$published, 'title' => "\u{00A0}\u{00A0}"]];
        yield 'v2 unparseable timestamp' => ['catalogue.experience.published.v2', [...$published, 'published_at' => '08/10/2026 10:00']];
        yield 'v1 missing price' => ['catalogue.experience.published.v1', array_diff_key([...$published, 'price' => 85.1], ['price_display' => true, 'price' => true])];
        yield 'booking with a zero party' => ['bookings.booking.confirmed.v1', [
            'booking_id' => self::BOOKING_ID, 'experience_id' => self::EXPERIENCE_ID, 'party_size' => 0, 'amount_minor' => 0,
            'currency' => 'GBP', 'departs_on' => '2026-11-14', 'confirmed_at' => '2026-10-08T09:30:00+00:00',
        ]];
        yield 'availability with a datetime instead of a date' => ['bookings.availability.changed.v1', [
            'experience_id' => self::EXPERIENCE_ID, 'date' => '2026-11-14T00:00:00Z', 'remaining_slots' => 3,
        ]];
        yield 'legacy import with live as Y/N' => ['legacy.experience.imported.v1', [
            'experience_id' => self::EXPERIENCE_ID, 'legacy_id' => '1003', 'slug' => 'x', 'title' => 'X', 'region_id' => 'lake-district',
            'price' => ['amount_minor' => 100, 'currency' => 'GBP'], 'live' => 'Y',
        ]];
    }

    #[DataProvider('producedEvents')]
    public function test_produced_payload_matches_its_published_schema(DomainEvent $event): void
    {
        $routingKey = sprintf('%s.v%d', $event::eventName(), $event::eventVersion());
        $this->assertFileExists(EventSchemas::directory().'/'.EventSchemas::payloadSchemaFile($routingKey), "No published contract for {$routingKey}.");

        $violations = (new EventSchemas)->payloadViolations($routingKey, $event->payload());

        $this->assertSame([], $violations);
    }

    #[DataProvider('invalidPayloads')]
    public function test_schema_rejects_a_contract_violation(string $routingKey, array $payload): void
    {
        $this->assertNotSame([], (new EventSchemas)->payloadViolations($routingKey, $payload));
    }

    public function test_v1_fixture_is_a_valid_v1_event_and_upcasts_to_a_valid_v2_event(): void
    {
        $schemas = new EventSchemas;
        $fixture = $this->v1Fixture();
        $this->assertSame([], $schemas->envelopeViolations($fixture), 'The v1 fixture itself must honour the envelope contract.');
        $this->assertSame([], $schemas->payloadViolations('catalogue.experience.published.v1', $fixture['payload']));

        [$upcast, $path] = (new UpcasterChain([new ExperiencePublishedV1ToV2]))->upcast(Envelope::fromArray($fixture));

        $this->assertSame([1], $path);
        $this->assertSame('catalogue.experience.published.v2', $upcast->routingKey());
        $this->assertSame([], $schemas->envelopeViolations($upcast->toArray()));
        $this->assertSame([], $schemas->payloadViolations($upcast->routingKey(), $upcast->payload));
        $this->assertSame(['amount_minor' => 8510, 'currency' => 'GBP'], $upcast->payload['price_display']);
    }

    public function test_envelope_schema_rejects_an_unknown_top_level_field_and_a_missing_aggregate_version(): void
    {
        $schemas = new EventSchemas;
        $fixture = $this->v1Fixture();

        $this->assertNotSame([], $schemas->envelopeViolations([...$fixture, 'type' => 'catalogue.experience.published.v1']));
        $this->assertNotSame([], $schemas->envelopeViolations(array_diff_key($fixture, ['aggregate_version' => true])));
        $this->assertNotSame([], $schemas->envelopeViolations([...$fixture, 'event_id' => 'not-a-ulid']));
    }

    public function test_every_schema_file_declares_an_id_matching_its_file_name(): void
    {
        $files = glob(EventSchemas::directory().'/*.schema.json') ?: [];
        $this->assertCount(7, $files);

        foreach ($files as $file) {
            $schema = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);

            $this->assertSame(EventSchemas::BASE_URI.basename($file), $schema['$id'], basename($file));
            $this->assertSame('https://json-schema.org/draft/2020-12/schema', $schema['$schema'], basename($file));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function v1Fixture(): array
    {
        $path = dirname(__DIR__, 2).'/Fixtures/events/catalogue.experience.published.v1.json';

        return json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
