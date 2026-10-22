<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Operations\ConsistencyChecker;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithCatalogue;
use Tests\Concerns\RefreshContexts;
use Tests\TestCase;

final class ConsistencyCheckerTest extends TestCase
{
    use InteractsWithCatalogue;
    use RefreshContexts;

    public function test_reports_in_sync_once_core_has_projected_every_published_experience(): void
    {
        $this->createPublishedExperience('Helvellyn via Striding Edge');
        $this->createPublishedExperience('Catbells sunset ridge walk', 4800);
        $this->deliverOutbox('cms', 'core.catalogue');

        $result = $this->checker()->run();

        $this->assertSame(['checked' => 2, 'drifted' => 0], ['checked' => $result['checked'], 'drifted' => $result['drifted']]);
        $this->assertDatabaseCount('consistency_drift', 0, 'ops');
    }

    public function test_drafts_are_not_expected_in_core(): void
    {
        $this->createDraftExperience();

        $this->assertSame(0, $this->checker()->run()['checked']);
    }

    public function test_flags_an_experience_core_has_never_seen_as_missing(): void
    {
        $id = $this->createPublishedExperience();

        $result = $this->checker()->run();

        $this->assertSame(1, $result['drifted']);
        $this->assertDatabaseHas('consistency_drift', ['run_id' => $result['run_id'], 'aggregate_id' => $id, 'kind' => 'missing', 'cms_version' => 1, 'core_version' => null], 'ops');
    }

    public function test_flags_core_as_stale_when_a_newer_version_has_not_been_applied(): void
    {
        $id = $this->createPublishedExperience();
        $this->deliverOutbox('cms', 'core.catalogue');
        ($this->app->make(PublishExperienceHandler::class))(new PublishExperience($id));

        $result = $this->checker()->run();

        $this->assertDatabaseHas('consistency_drift', ['run_id' => $result['run_id'], 'aggregate_id' => $id, 'kind' => 'stale', 'cms_version' => 2, 'core_version' => 1], 'ops');
    }

    public function test_flags_an_experience_only_core_knows_about_as_an_orphan(): void
    {
        DB::connection('core')->table('catalogue_experiences')->insert([
            'experience_id' => '01K74ZQ9M2H3J4K5M6N7P8Q9RS', 'title' => 'Ghost listing', 'slug' => 'ghost', 'region_id' => 'cairngorms',
            'price_minor' => 5000, 'currency' => 'GBP', 'status' => 'published', 'version' => 3, 'source' => 'cms',
            'last_event_id' => '01K7000000000000000000000A', 'synced_at' => now(),
        ]);

        $result = $this->checker()->run();

        $this->assertDatabaseHas('consistency_drift', ['run_id' => $result['run_id'], 'kind' => 'orphan', 'cms_version' => null, 'core_version' => 3], 'ops');
    }

    private function checker(): ConsistencyChecker
    {
        return $this->app->make(ConsistencyChecker::class);
    }
}
