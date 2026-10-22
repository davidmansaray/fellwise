<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Infrastructure\Messaging;

use Fellwise\Shared\Application\Messaging\CorrelationContext;
use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\MessageProcessor;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingResult;
use Fellwise\Shared\Infrastructure\Messaging\ProcessingStatus;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshContexts;
use Tests\Support\Envelopes;
use Tests\TestCase;

/**
 * End to end through the real consumer config: decode → upcast → fault check
 * → inbox claim + CatalogueExperienceProjector in one core transaction.
 */
final class MessageProcessorTest extends TestCase
{
    use RefreshContexts;

    private const string QUEUE = 'core.catalogue';

    private const string EXPERIENCE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function unreadableBodies(): iterable
    {
        yield 'truncated JSON' => ['{"event_id": ', 'Malformed envelope: Syntax error'];
        yield 'JSON array of strings' => ['["not", "an", "envelope"]', 'Malformed envelope: Envelope is missing [event_id].'];
        yield 'JSON scalar' => ['42', 'Malformed envelope: Envelope JSON must decode to an object.'];
        yield 'empty body' => ['', 'Malformed envelope: Syntax error'];
    }

    public function test_a_new_event_is_projected_claimed_and_logged(): void
    {
        $envelope = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1, 'Helvellyn via Striding Edge', 8500);

        $result = $this->process($envelope);

        $this->assertSame(ProcessingStatus::Processed, $result->status);
        $this->assertSame($envelope->eventId, $result->envelope?->eventId);
        $this->assertDatabaseHas('catalogue_experiences', [
            'experience_id' => self::EXPERIENCE_ID,
            'title' => 'Helvellyn via Striding Edge',
            'slug' => 'helvellyn-via-striding-edge',
            'region_id' => 'lake-district',
            'price_minor' => 8500,
            'currency' => 'GBP',
            'status' => 'published',
            'version' => 1,
            'source' => 'cms',
            'last_event_id' => $envelope->eventId,
        ], 'core');
        $this->assertDatabaseHas('inbox_messages', [
            'event_id' => $envelope->eventId,
            'consumer' => self::QUEUE,
            'event_name' => 'catalogue.experience.published',
            'event_version' => 2,
            'aggregate_id' => self::EXPERIENCE_ID,
        ], 'core');
        $this->assertSame([['processed', 1, null, 'catalogue.experience.published.v2']], $this->deliveryLog());
    }

    public function test_the_same_envelope_twice_is_a_duplicate_with_a_single_effect(): void
    {
        $envelope = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1);
        $this->process($envelope);

        $result = $this->process($envelope);

        $this->assertSame(ProcessingStatus::Duplicate, $result->status);
        $this->assertDatabaseCount('catalogue_experiences', 1, 'core');
        $this->assertDatabaseCount('inbox_messages', 1, 'core');
        $this->assertSame(['processed', 'duplicate'], array_column($this->deliveryLog(), 0));
    }

    public function test_an_injected_fault_fails_into_the_first_retry_tier_without_claiming_the_inbox(): void
    {
        $this->app->make(FaultInjector::class)->set(self::QUEUE, true);
        $envelope = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1);

        $result = $this->process($envelope);

        $this->assertSame(ProcessingStatus::Failed, $result->status);
        $this->assertNotNull($result->retry);
        $this->assertFalse($result->retry->parked);
        $this->assertSame('5s', $result->retry->tier);
        $this->assertSame('core.catalogue.retry.5s', $result->retry->routingKey);
        $this->assertSame(1, $result->retry->retryCount);
        $this->assertStringContainsString('Connection refused', (string) $result->error);
        $this->assertDatabaseCount('inbox_messages', 0, 'core');
        $this->assertDatabaseCount('catalogue_experiences', 0, 'core');
        $this->assertSame([['retry_scheduled', 1, '5s', 'catalogue.experience.published.v2']], $this->deliveryLog());
    }

    public function test_a_retried_message_is_processed_once_the_fault_clears(): void
    {
        $faults = $this->app->make(FaultInjector::class);
        $faults->set(self::QUEUE, true);
        $envelope = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1);
        $this->process($envelope);
        $faults->set(self::QUEUE, false);

        $result = $this->process($envelope, ['x-retry-count' => 1]);

        $this->assertSame(ProcessingStatus::Processed, $result->status);
        $this->assertDatabaseCount('inbox_messages', 1, 'core');
        $this->assertSame([['retry_scheduled', 1, '5s'], ['processed', 2, null]], array_map(fn (array $row): array => array_slice($row, 0, 3), $this->deliveryLog()));
    }

    public function test_a_failure_after_the_last_retry_tier_is_parked(): void
    {
        $this->app->make(FaultInjector::class)->set(self::QUEUE, true);

        $result = $this->process(Envelopes::experiencePublished(self::EXPERIENCE_ID, 1), ['x-retry-count' => 3]);

        $this->assertSame(ProcessingStatus::Failed, $result->status);
        $this->assertNotNull($result->retry);
        $this->assertTrue($result->retry->parked);
        $this->assertNull($result->retry->tier);
        $this->assertSame(self::QUEUE, $result->retry->routingKey);
        $this->assertSame(3, $result->retry->retryCount);
        $this->assertDatabaseCount('inbox_messages', 0, 'core');
        $this->assertSame([['parked', 4, null, 'catalogue.experience.published.v2']], $this->deliveryLog());
    }

    public function test_a_contract_violation_is_parked_on_the_first_attempt_and_rolls_back_its_inbox_claim(): void
    {
        $broken = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1)->withPayload(2, ['title' => 'No price, slug or region']);

        $result = $this->process($broken);

        $this->assertSame(ProcessingStatus::Failed, $result->status);
        $this->assertTrue($result->retry?->parked, 'Retrying cannot fix a missing field, so it skips the tiers.');
        $this->assertStringContainsString('missing slug, region_id, price_display.amount_minor', (string) $result->error);
        $this->assertDatabaseCount('inbox_messages', 0, 'core');
        $this->assertDatabaseCount('catalogue_experiences', 0, 'core');
        $this->assertSame([['parked', 1, null, 'catalogue.experience.published.v2']], $this->deliveryLog());
    }

    public function test_a_wrongly_shaped_field_is_treated_as_permanent(): void
    {
        $wrongType = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1)->withPayload(2, [
            'title' => 'Helvellyn', 'slug' => 'helvellyn', 'region_id' => 'lake-district',
            'price_display' => 'eighty-five pounds',
        ]);

        $result = $this->process($wrongType);

        $this->assertTrue($result->retry?->parked);
    }

    public function test_an_older_event_arriving_after_a_newer_one_does_not_overwrite_it(): void
    {
        $newer = Envelopes::experiencePublished(self::EXPERIENCE_ID, 3, 'Helvellyn at dawn', 9900);
        $older = Envelopes::experiencePublished(self::EXPERIENCE_ID, 2, 'Helvellyn via Striding Edge', 8500);
        $this->process($newer);

        $result = $this->process($older);

        $this->assertSame(ProcessingStatus::Processed, $result->status, 'The stale event is consumed (acked), just not applied.');
        $this->assertDatabaseHas('catalogue_experiences', [
            'experience_id' => self::EXPERIENCE_ID,
            'title' => 'Helvellyn at dawn',
            'price_minor' => 9900,
            'version' => 3,
            'last_event_id' => $newer->eventId,
        ], 'core');
    }

    public function test_a_v1_envelope_is_upcast_before_it_is_applied(): void
    {
        $v1 = Envelopes::experiencePublishedV1(self::EXPERIENCE_ID, 1, 85.1);

        $result = $this->process($v1);

        $this->assertSame(ProcessingStatus::Processed, $result->status);
        $this->assertSame(2, $result->envelope?->eventVersion);
        $this->assertDatabaseHas('catalogue_experiences', ['experience_id' => self::EXPERIENCE_ID, 'price_minor' => 8510, 'currency' => 'GBP', 'title' => 'Scafell Pike sunrise'], 'core');
        $this->assertDatabaseHas('inbox_messages', ['event_id' => $v1->eventId, 'event_version' => 2], 'core');
        $this->assertSame('catalogue.experience.published.v1', $this->deliveryLog()[0][3], 'Telemetry records what was actually received.');
    }

    public function test_an_event_the_queue_does_not_subscribe_to_is_ignored(): void
    {
        $result = $this->process(Envelopes::bookingConfirmed(self::EXPERIENCE_ID));

        $this->assertSame(ProcessingStatus::Ignored, $result->status);
        $this->assertDatabaseCount('inbox_messages', 0, 'core');
        $this->assertDatabaseCount('inbox_messages', 0, 'cms');
        $this->assertSame([], $this->deliveryLog());
    }

    public function test_an_unknown_queue_ignores_every_event(): void
    {
        $result = $this->app->make(MessageProcessor::class)->process('reporting.unknown', Envelopes::experiencePublished(self::EXPERIENCE_ID, 1)->toJson());

        $this->assertSame(ProcessingStatus::Ignored, $result->status);
        $this->assertDatabaseCount('catalogue_experiences', 0, 'core');
    }

    #[DataProvider('unreadableBodies')]
    public function test_an_unreadable_body_is_rejected_for_dead_lettering(string $body, string $expectedError): void
    {
        $result = $this->app->make(MessageProcessor::class)->process(self::QUEUE, $body);

        $this->assertSame(ProcessingStatus::Rejected, $result->status);
        $this->assertNull($result->envelope);
        $this->assertSame($expectedError, $result->error);
        $this->assertDatabaseCount('inbox_messages', 0, 'core');
        $this->assertSame([], $this->deliveryLog());
    }

    public function test_a_replay_header_is_recorded_in_telemetry(): void
    {
        $this->process(Envelopes::experiencePublished(self::EXPERIENCE_ID, 1), ['x-replay' => true]);

        $this->assertSame(1, (int) DB::connection('ops')->table('delivery_attempts')->value('is_replay'));
    }

    public function test_correlation_context_is_reset_after_each_message(): void
    {
        $envelope = Envelopes::experiencePublished(self::EXPERIENCE_ID, 1);

        $this->process($envelope);

        $correlation = $this->app->make(CorrelationContext::class);
        $this->assertNull($correlation->causationId());
        $this->assertNotSame($envelope->correlationId, $correlation->correlationId());
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function process(Envelope $envelope, array $headers = []): ProcessingResult
    {
        return $this->app->make(MessageProcessor::class)->process(self::QUEUE, $envelope->toJson(), $headers);
    }

    /**
     * @return list<array{string, int, ?string, string}> [outcome, attempt, tier, event_name]
     */
    private function deliveryLog(): array
    {
        return DB::connection('ops')->table('delivery_attempts')->orderBy('id')->get()
            ->map(fn (object $row): array => [$row->outcome, (int) $row->attempt, $row->tier, $row->event_name])
            ->all();
    }
}
