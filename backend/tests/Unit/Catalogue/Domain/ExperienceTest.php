<?php

declare(strict_types=1);

namespace Tests\Unit\Catalogue\Domain;

use DateTimeImmutable;
use Fellwise\Catalogue\Domain\Event\ExperiencePublished;
use Fellwise\Catalogue\Domain\Event\ExperienceUnpublished;
use Fellwise\Catalogue\Domain\Event\LegacyExperienceImported;
use Fellwise\Catalogue\Domain\Exception\CannotPublishExperience;
use Fellwise\Catalogue\Domain\Experience;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Fellwise\Catalogue\Domain\Money;
use PHPUnit\Framework\TestCase;

final class ExperienceTest extends TestCase
{
    private const string ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    public function test_publishing_a_draft_records_a_v2_published_event_and_bumps_the_version(): void
    {
        $experience = $this->draft();
        $at = new DateTimeImmutable('2026-10-08T10:00:00+00:00');

        $experience->publish($at);

        $events = $experience->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ExperiencePublished::class, $events[0]);
        $this->assertSame(2, $events[0]::eventVersion());
        $this->assertSame([
            'experience_id' => self::ID,
            'slug' => 'helvellyn-via-striding-edge',
            'title' => 'Helvellyn via Striding Edge',
            'region_id' => 'lake-district',
            'guide_id' => 'guide-1',
            'price_display' => ['amount_minor' => 8500, 'currency' => 'GBP'],
            'published_at' => '2026-10-08T10:00:00+00:00',
        ], $events[0]->payload());
        $this->assertSame(1, $experience->version());
        $this->assertSame(ExperienceStatus::Published, $experience->status());
        $this->assertSame($at, $experience->publishedAt());
    }

    public function test_releasing_events_empties_the_pending_list(): void
    {
        $experience = $this->draft();
        $experience->publish(new DateTimeImmutable);
        $experience->releaseEvents();

        $this->assertSame([], $experience->releaseEvents());
    }

    public function test_revising_a_live_experience_republishes_its_new_content(): void
    {
        $experience = $this->draft();
        $experience->publish(new DateTimeImmutable);
        $experience->releaseEvents();

        $experience->revise('Helvellyn at dawn', 'helvellyn-at-dawn', 'lake-district', null, Money::gbp(9900));

        $events = $experience->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ExperiencePublished::class, $events[0]);
        $this->assertSame('Helvellyn at dawn', $events[0]->payload()['title']);
        $this->assertSame(['amount_minor' => 9900, 'currency' => 'GBP'], $events[0]->payload()['price_display']);
        $this->assertNull($events[0]->payload()['guide_id']);
        $this->assertSame(2, $experience->version());
    }

    public function test_revising_a_draft_changes_content_without_emitting_events(): void
    {
        $experience = $this->draft();

        $experience->revise('Helvellyn at dawn', 'helvellyn-at-dawn', 'lake-district', null, Money::gbp(9900));

        $this->assertSame([], $experience->releaseEvents());
        $this->assertSame(0, $experience->version());
        $this->assertSame('Helvellyn at dawn', $experience->title());
        $this->assertTrue($experience->price()->equals(Money::gbp(9900)));
    }

    public function test_unpublishing_a_live_experience_records_the_reason(): void
    {
        $experience = $this->draft();
        $experience->publish(new DateTimeImmutable);
        $experience->releaseEvents();

        $experience->unpublish('Guide on leave');

        $events = $experience->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(ExperienceUnpublished::class, $events[0]);
        $this->assertSame(['experience_id' => self::ID, 'reason' => 'Guide on leave'], $events[0]->payload());
        $this->assertSame(ExperienceStatus::Unpublished, $experience->status());
        $this->assertSame(2, $experience->version());
    }

    public function test_refuses_to_unpublish_an_experience_that_is_not_live(): void
    {
        $experience = $this->draft();

        try {
            $experience->unpublish('Never went live');
            $this->fail('Unpublishing a draft must be refused.');
        } catch (CannotPublishExperience $exception) {
            $this->assertSame('Only a published experience can be unpublished.', $exception->getMessage());
        }

        $this->assertSame([], $experience->releaseEvents());
        $this->assertSame(0, $experience->version());
        $this->assertSame(ExperienceStatus::Draft, $experience->status());
    }

    public function test_refuses_to_publish_without_a_title(): void
    {
        $experience = $this->draft(title: '   ');

        $this->expectExceptionObject(CannotPublishExperience::missing('title'));

        $experience->publish(new DateTimeImmutable);
    }

    public function test_refuses_to_publish_at_a_zero_price_and_records_nothing(): void
    {
        $experience = $this->draft(priceMinor: 0);

        try {
            $experience->publish(new DateTimeImmutable);
            $this->fail('Publishing at £0 must be refused.');
        } catch (CannotPublishExperience $exception) {
            $this->assertSame('An experience needs a price before it can be published.', $exception->getMessage());
        }

        $this->assertSame(ExperienceStatus::Draft, $experience->status());
        $this->assertSame(0, $experience->version());
        $this->assertSame([], $experience->releaseEvents());
    }

    public function test_legacy_import_of_a_live_listing_is_published_at_version_one(): void
    {
        $experience = Experience::importFromLegacy(self::ID, '1003', 'Wild swim: Borrowdale', 'wild-swim-borrowdale', 'lake-district', Money::gbp(8400), true);

        $events = $experience->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(LegacyExperienceImported::class, $events[0]);
        $this->assertSame([
            'experience_id' => self::ID,
            'legacy_id' => '1003',
            'slug' => 'wild-swim-borrowdale',
            'title' => 'Wild swim: Borrowdale',
            'region_id' => 'lake-district',
            'price' => ['amount_minor' => 8400, 'currency' => 'GBP'],
            'live' => true,
        ], $events[0]->payload());
        $this->assertSame(1, $experience->version());
        $this->assertSame(ExperienceStatus::Published, $experience->status());
        $this->assertNotNull($experience->publishedAt());
        $this->assertSame('1003', $experience->legacyId());
        $this->assertNull($experience->guideId());
    }

    public function test_legacy_import_of_a_withdrawn_listing_is_unpublished_without_a_publish_date(): void
    {
        $experience = Experience::importFromLegacy(self::ID, '1002', 'Scramble: Grassington', 'scramble-grassington', 'yorkshire-dales', Money::gbp(3338), false);

        $this->assertSame(ExperienceStatus::Unpublished, $experience->status());
        $this->assertNull($experience->publishedAt());
        $this->assertFalse($experience->releaseEvents()[0]->payload()['live']);
    }

    /**
     * Production bug: importFromLegacy() puts a listing live without the
     * invariants publish() enforces, so a TrailDesk row priced "£0.00" becomes
     * a bookable experience at £0 — a state no CMS action can create.
     */
    public function test_refuses_to_import_a_live_listing_at_a_zero_price(): void
    {
        $this->expectException(CannotPublishExperience::class);

        Experience::importFromLegacy(self::ID, '1999', 'Free taster walk', 'free-taster-walk', 'peak-district', Money::gbp(0), true);
    }

    private function draft(string $title = 'Helvellyn via Striding Edge', int $priceMinor = 8500): Experience
    {
        return new Experience(
            self::ID, $title, 'helvellyn-via-striding-edge', 'lake-district', 'guide-1',
            Money::gbp($priceMinor), ExperienceStatus::Draft, 0,
        );
    }
}
