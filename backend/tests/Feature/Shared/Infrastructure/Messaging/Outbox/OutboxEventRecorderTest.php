<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Infrastructure\Messaging\Outbox;

use DateTimeImmutable;
use Fellwise\Catalogue\Application\PublishExperience;
use Fellwise\Catalogue\Application\PublishExperienceHandler;
use Fellwise\Catalogue\Domain\Event\ExperienceUnpublished;
use Fellwise\Catalogue\Domain\Experience;
use Fellwise\Catalogue\Domain\ExperienceRepository;
use Fellwise\Catalogue\Domain\ExperienceStatus;
use Fellwise\Catalogue\Domain\Money;
use Fellwise\Shared\Application\Messaging\CorrelationContext;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxEventRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Concerns\InteractsWithCatalogue;
use Tests\Concerns\RefreshContexts;
use Tests\TestCase;

final class OutboxEventRecorderTest extends TestCase
{
    use InteractsWithCatalogue;
    use RefreshContexts;

    public function test_publishing_commits_the_aggregate_and_its_outbox_row_together(): void
    {
        $id = $this->createDraftExperience('Helvellyn via Striding Edge', 8500);

        $this->createPublishedExperienceFrom($id);

        $this->assertDatabaseHas('experiences', ['id' => $id, 'status' => 'published', 'version' => 1], 'cms');
        $row = DB::connection('cms')->table('outbox_messages')->sole();
        $this->assertSame('catalogue.experience.published', $row->event_name);
        $this->assertSame(2, (int) $row->event_version);
        $this->assertSame('experience', $row->aggregate_type);
        $this->assertSame($id, $row->aggregate_id);
        $this->assertSame(1, (int) $row->aggregate_version);
        $this->assertSame('fellwise-cms', $row->producer);
        $this->assertNull($row->published_at);
        $this->assertSame(0, (int) $row->publish_attempts);
        $this->assertMatchesRegularExpression('/^[0-9A-HJKMNP-TV-Z]{26}$/', $row->id);
        $this->assertEquals(['amount_minor' => 8500, 'currency' => 'GBP'], json_decode($row->payload, true)['price_display']);
        $this->assertDatabaseCount('outbox_messages', 0, 'core');
    }

    public function test_rolled_back_transaction_leaves_neither_the_aggregate_nor_the_event(): void
    {
        $repository = $this->app->make(ExperienceRepository::class);
        $experience = $this->draft();
        $experience->publish(new DateTimeImmutable);

        try {
            DB::connection('cms')->transaction(function () use ($repository, $experience): void {
                $repository->save($experience);

                throw new RuntimeException('Editor validation failed after save');
            });
            $this->fail('The transaction should have rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Editor validation failed after save', $exception->getMessage());
        }

        $this->assertDatabaseCount('experiences', 0, 'cms');
        $this->assertDatabaseCount('outbox_messages', 0, 'cms');
    }

    public function test_refuses_to_record_outside_a_transaction(): void
    {
        $recorder = new OutboxEventRecorder(DB::connection('cms'), new CorrelationContext, 'fellwise-cms');

        try {
            $recorder->record(new ExperienceUnpublished((string) Str::ulid(), 'Season ended'), 2);
            $this->fail('Recording at transaction level 0 must be refused.');
        } catch (LogicException $exception) {
            $this->assertSame('Events must be recorded inside the aggregate\'s transaction.', $exception->getMessage());
        }

        $this->assertDatabaseCount('outbox_messages', 0, 'cms');
    }

    public function test_numbers_several_pending_events_with_consecutive_aggregate_versions(): void
    {
        $experience = $this->draft();
        $experience->publish(new DateTimeImmutable);
        $experience->unpublish('Guide injured');

        DB::connection('cms')->transaction(fn () => $this->app->make(ExperienceRepository::class)->save($experience));

        $rows = DB::connection('cms')->table('outbox_messages')->orderBy('aggregate_version')->get(['event_name', 'aggregate_version']);
        $this->assertSame(
            [['catalogue.experience.published', 1], ['catalogue.experience.unpublished', 2]],
            $rows->map(fn (object $row): array => [$row->event_name, (int) $row->aggregate_version])->all(),
        );
        $this->assertDatabaseHas('experiences', ['id' => $experience->id, 'version' => 2, 'status' => 'unpublished'], 'cms');
    }

    public function test_stamps_correlation_and_causation_from_the_message_being_handled(): void
    {
        $correlation = new CorrelationContext;
        $correlation->continueFrom('01K74ZQ9M2H3J4K5M6N7P8Q9CR', '01K74ZQ9M2H3J4K5M6N7P8Q9CA');
        $recorder = new OutboxEventRecorder(DB::connection('core'), $correlation, 'fellwise-core');

        $envelope = DB::connection('core')->transaction(
            fn () => $recorder->record(new ExperienceUnpublished('01K74ZQ9M2H3J4K5M6N7P8Q9RS', 'x'), 7),
        );

        $this->assertDatabaseHas('outbox_messages', [
            'id' => $envelope->eventId,
            'correlation_id' => '01K74ZQ9M2H3J4K5M6N7P8Q9CR',
            'causation_id' => '01K74ZQ9M2H3J4K5M6N7P8Q9CA',
            'aggregate_version' => 7,
            'producer' => 'fellwise-core',
        ], 'core');
    }

    public function test_events_recorded_in_one_command_share_a_correlation_id_and_have_no_cause(): void
    {
        $experience = $this->draft();
        $experience->publish(new DateTimeImmutable);
        $experience->unpublish('Guide injured');

        DB::connection('cms')->transaction(fn () => $this->app->make(ExperienceRepository::class)->save($experience));

        $rows = DB::connection('cms')->table('outbox_messages')->get(['correlation_id', 'causation_id']);
        $this->assertCount(1, $rows->pluck('correlation_id')->unique());
        $this->assertSame([null, null], $rows->pluck('causation_id')->all());
    }

    private function createPublishedExperienceFrom(string $id): void
    {
        ($this->app->make(PublishExperienceHandler::class))(new PublishExperience($id));
    }

    private function draft(): Experience
    {
        return new Experience((string) Str::ulid(), 'Helvellyn', 'helvellyn', 'lake-district', null, Money::gbp(8500), ExperienceStatus::Draft, 0);
    }
}
