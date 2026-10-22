<?php

declare(strict_types=1);

namespace Tests\Feature\Bookings\Infrastructure\Messaging;

use Fellwise\Catalogue\Application\ReviseExperience;
use Fellwise\Catalogue\Application\ReviseExperienceHandler;
use Fellwise\Catalogue\Application\UnpublishExperience;
use Fellwise\Catalogue\Application\UnpublishExperienceHandler;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingStatus;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCatalogue;
use Tests\Concerns\RefreshContexts;
use Tests\Support\Envelopes;
use Tests\TestCase;

/**
 * Core's catalogue projection, driven through the real consumer pipeline.
 */
final class CatalogueExperienceProjectorTest extends TestCase
{
    use InteractsWithCatalogue;
    use RefreshContexts;

    private const string QUEUE = 'core.catalogue';

    private const string EXPERIENCE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    public function test_cms_edits_reach_core_through_outbox_relay_and_consumer(): void
    {
        $id = $this->createPublishedExperience('Helvellyn via Striding Edge', 8500);
        ($this->app->make(ReviseExperienceHandler::class))(new ReviseExperience($id, 'Helvellyn at dawn', 'helvellyn-at-dawn', 'lake-district', null, 9900));

        $results = $this->deliverOutbox('cms', self::QUEUE);

        $this->assertSame([ProcessingStatus::Processed, ProcessingStatus::Processed], array_map(fn ($result) => $result->status, $results));
        $this->assertDatabaseHas('catalogue_experiences', [
            'experience_id' => $id, 'title' => 'Helvellyn at dawn', 'slug' => 'helvellyn-at-dawn', 'price_minor' => 9900, 'status' => 'published', 'version' => 2,
        ], 'core');
        $this->assertSame(
            DB::connection('cms')->table('experiences')->where('id', $id)->value('sync_hash'),
            DB::connection('core')->table('catalogue_experiences')->where('experience_id', $id)->value('sync_hash'),
        );
    }

    public function test_unpublishing_marks_the_projection_unpublished_at_the_new_version(): void
    {
        $id = $this->createPublishedExperience();
        ($this->app->make(UnpublishExperienceHandler::class))(new UnpublishExperience($id, 'Guide on leave'));

        $this->deliverOutbox('cms', self::QUEUE);

        $this->assertDatabaseHas('catalogue_experiences', ['experience_id' => $id, 'status' => 'unpublished', 'version' => 2, 'title' => 'Helvellyn via Striding Edge'], 'core');
    }

    public function test_an_older_unpublish_does_not_take_down_a_newer_publish(): void
    {
        $this->consume(self::QUEUE, Envelopes::experiencePublished(self::EXPERIENCE_ID, 3));

        $this->consume(self::QUEUE, Envelopes::experienceUnpublished(self::EXPERIENCE_ID, 2));

        $this->assertDatabaseHas('catalogue_experiences', ['experience_id' => self::EXPERIENCE_ID, 'status' => 'published', 'version' => 3], 'core');
    }

    public function test_an_event_at_the_same_version_with_a_new_id_does_not_overwrite(): void
    {
        $original = Envelopes::experiencePublished(self::EXPERIENCE_ID, 2, 'Original');
        $this->consume(self::QUEUE, $original);

        $result = $this->consume(self::QUEUE, Envelopes::experiencePublished(self::EXPERIENCE_ID, 2, 'Impostor at the same version'));

        $this->assertSame(ProcessingStatus::Processed, $result->status);
        $this->assertDatabaseHas('catalogue_experiences', ['experience_id' => self::EXPERIENCE_ID, 'title' => 'Original', 'last_event_id' => $original->eventId], 'core');
    }

    public function test_a_live_legacy_import_is_bookable_and_tagged_as_traildesk(): void
    {
        $this->consume(self::QUEUE, Envelopes::legacyExperienceImported(self::EXPERIENCE_ID, live: true, priceMinor: 4500));

        $this->assertDatabaseHas('catalogue_experiences', [
            'experience_id' => self::EXPERIENCE_ID, 'title' => 'Kayak tour: Solva', 'region_id' => 'pembrokeshire',
            'price_minor' => 4500, 'status' => 'published', 'source' => 'traildesk', 'version' => 1,
        ], 'core');
    }

    public function test_a_withdrawn_legacy_import_is_projected_unpublished(): void
    {
        $this->consume(self::QUEUE, Envelopes::legacyExperienceImported(self::EXPERIENCE_ID, live: false));

        $this->assertDatabaseHas('catalogue_experiences', ['experience_id' => self::EXPERIENCE_ID, 'status' => 'unpublished'], 'core');
    }

    public function test_a_cms_republish_takes_over_a_legacy_import(): void
    {
        $this->consume(self::QUEUE, Envelopes::legacyExperienceImported(self::EXPERIENCE_ID));

        $this->consume(self::QUEUE, Envelopes::experiencePublished(self::EXPERIENCE_ID, 2, 'Sea kayak: Solva harbour', 5200));

        $this->assertDatabaseHas('catalogue_experiences', ['experience_id' => self::EXPERIENCE_ID, 'source' => 'cms', 'price_minor' => 5200, 'version' => 2], 'core');
    }

    /**
     * Production bug: retries reorder messages (ADR-002), so an unpublish (v2)
     * can arrive before the publish (v1) it supersedes — e.g. v1 hit a fault and
     * sat in the 5s tier. The unpublish UPDATE matches no row, is acked and
     * claimed in the inbox, then the late v1 INSERTs the experience as
     * `published`. Core keeps selling an experience the CMS took down.
     */
    public function test_an_unpublish_that_overtakes_its_publish_still_leaves_the_experience_unpublished(): void
    {
        $this->consume(self::QUEUE, Envelopes::experienceUnpublished(self::EXPERIENCE_ID, 2));

        $this->consume(self::QUEUE, Envelopes::experiencePublished(self::EXPERIENCE_ID, 1));

        $this->assertDatabaseMissing('catalogue_experiences', ['experience_id' => self::EXPERIENCE_ID, 'status' => 'published'], 'core');
    }
}
