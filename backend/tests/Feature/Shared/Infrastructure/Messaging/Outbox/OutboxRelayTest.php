<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Infrastructure\Messaging\Outbox;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\Outboxes;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxRelay;
use Fellwise\Shared\Infrastructure\Messaging\Outbox\OutboxTable;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\RefreshContexts;
use Tests\Fakes\InMemoryMessagePublisher;
use Tests\Support\Envelopes;
use Tests\TestCase;

final class OutboxRelayTest extends TestCase
{
    use RefreshContexts;

    private const string FIRST = '01K7000000000000000000000A';

    private const string SECOND = '01K7000000000000000000000B';

    private const string THIRD = '01K7000000000000000000000C';

    private const string EXPERIENCE_ID = '01K74ZQ9M2H3J4K5M6N7P8Q9RS';

    public function test_publishes_pending_rows_once_in_id_order_and_stamps_them(): void
    {
        $broker = $this->fakePublisher();
        $this->recordInCms(self::SECOND, 2);
        $this->recordInCms(self::FIRST, 1);
        $this->recordInCms(self::THIRD, 3);

        $published = $this->relay('cms')->relayBatch();

        $this->assertSame(3, $published);
        $this->assertSame([self::FIRST, self::SECOND, self::THIRD], $broker->confirmedEventIds());
        $this->assertSame(array_fill(0, 3, 'catalogue.experience.published.v2'), $broker->confirmedRoutingKeys());
        $this->assertSame(['fellwise.events'], array_values(array_unique(array_column($broker->confirmed(), 'exchange'))));
        $this->assertSame(0, DB::connection('cms')->table('outbox_messages')->whereNull('published_at')->count());
        $this->assertSame([1], DB::connection('cms')->table('outbox_messages')->distinct()->pluck('publish_attempts')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(
            [[self::FIRST, 'relay', 'published'], [self::SECOND, 'relay', 'published'], [self::THIRD, 'relay', 'published']],
            DB::connection('ops')->table('delivery_attempts')->orderBy('event_id')->get()->map(fn (object $row): array => [$row->event_id, $row->consumer, $row->outcome])->all(),
        );
    }

    public function test_second_run_publishes_nothing(): void
    {
        $broker = $this->fakePublisher();
        $this->recordInCms(self::FIRST, 1);
        $relay = $this->relay('cms');
        $relay->relayBatch();

        $published = $relay->relayBatch();

        $this->assertSame(0, $published);
        $this->assertSame(1, $broker->publishAttempts());
        $this->assertDatabaseCount('delivery_attempts', 1, 'ops');
    }

    public function test_publishes_the_broker_body_from_the_stored_row_exactly(): void
    {
        $broker = $this->fakePublisher();
        $original = $this->recordInCms(self::FIRST, 4);

        $this->relay('cms')->relayBatch();

        $sent = $broker->confirmed()[0]['envelope'];
        $this->assertSame($original->eventId, $sent->eventId);
        $this->assertSame($original->aggregateVersion, $sent->aggregateVersion);
        $this->assertSame($original->correlationId, $sent->correlationId);
        $this->assertSame($original->occurredAt->format('Y-m-d H:i:s.u'), $sent->occurredAt->format('Y-m-d H:i:s.u'));
        $this->assertEquals($original->payload, $sent->payload);
    }

    public function test_honours_the_batch_limit_and_continues_where_it_stopped(): void
    {
        $broker = $this->fakePublisher();
        $this->recordInCms(self::FIRST, 1);
        $this->recordInCms(self::SECOND, 2);
        $this->recordInCms(self::THIRD, 3);
        $relay = $this->relay('cms');

        $this->assertSame(2, $relay->relayBatch(2));
        $this->assertSame([self::FIRST, self::SECOND], $broker->confirmedEventIds());

        $this->assertSame(1, $relay->relayBatch(2));
        $this->assertSame([self::FIRST, self::SECOND, self::THIRD], $broker->confirmedEventIds());
    }

    public function test_failing_publisher_leaves_the_row_unpublished_with_its_error_and_attempt_count(): void
    {
        $broker = $this->fakePublisher()->failEveryPublish('AMQP connection refused');
        $this->recordInCms(self::FIRST, 1);
        $relay = $this->relay('cms');

        $this->assertSame(0, $relay->relayBatch());
        $this->assertSame(0, $relay->relayBatch());

        $row = DB::connection('cms')->table('outbox_messages')->sole();
        $this->assertNull($row->published_at);
        $this->assertSame('AMQP connection refused', $row->last_error);
        $this->assertSame(2, (int) $row->publish_attempts);
        $this->assertSame([], $broker->confirmed());
        $this->assertDatabaseCount('delivery_attempts', 0, 'ops');
    }

    /**
     * Regression: a mid-batch channel loss used to leave earlier rows stamped
     * as published although their confirms died with the channel.
     */
    public function test_a_mid_batch_failure_rolls_back_the_whole_batch_so_nothing_is_stamped_without_a_confirm(): void
    {
        $broker = $this->fakePublisher()->failPublishWhen(fn (Envelope $envelope): bool => $envelope->eventId === self::SECOND, 'Channel closed');
        $this->recordInCms(self::FIRST, 1);
        $this->recordInCms(self::SECOND, 2);
        $this->recordInCms(self::THIRD, 3);

        $published = $this->relay('cms')->relayBatch();

        $this->assertSame(0, $published);
        $this->assertSame([], $broker->confirmedEventIds());
        $this->assertSame([
            self::FIRST => [false, 0, null],
            self::SECOND => [false, 1, 'Channel closed'],
            self::THIRD => [false, 0, null],
        ], $this->outboxState());
    }

    public function test_after_a_failed_batch_the_next_run_publishes_everything_in_order(): void
    {
        $this->recordInCms(self::FIRST, 1);
        $this->recordInCms(self::SECOND, 2);
        $brokerDown = true;
        $broker = $this->fakePublisher()->failPublishWhen(function (Envelope $envelope) use (&$brokerDown): bool {
            return $brokerDown && $envelope->eventId === self::SECOND;
        }, 'Channel closed');
        $this->relay('cms')->relayBatch();

        $brokerDown = false;
        $published = $this->relay('cms')->relayBatch();

        $this->assertSame(2, $published);
        $this->assertSame([self::FIRST, self::SECOND], $broker->confirmedEventIds());
    }

    public function test_a_row_that_failed_is_published_and_its_error_cleared_once_the_broker_recovers(): void
    {
        $failing = new InMemoryMessagePublisher;
        $failing->failEveryPublish('Connection reset by peer');
        $this->recordInCms(self::FIRST, 1);
        (new OutboxRelay(DB::connection('cms'), $failing, $this->app->make(DeliveryLog::class)))->relayBatch();
        $broker = $this->fakePublisher();

        $published = $this->relay('cms')->relayBatch();

        $this->assertSame(1, $published);
        $this->assertSame([self::FIRST], $broker->confirmedEventIds());
        $this->assertSame([self::FIRST => [true, 2, null]], $this->outboxState());
    }

    public function test_a_nacked_confirm_stamps_nothing_so_the_whole_batch_is_retried(): void
    {
        $this->fakePublisher()->nackEveryConfirm('Broker rejected 2 and could not route 0 message(s).');
        $this->recordInCms(self::FIRST, 1);
        $this->recordInCms(self::SECOND, 2);

        try {
            $this->relay('cms')->relayBatch();
            $this->fail('A nacked confirm must surface as an exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Broker rejected 2 and could not route 0 message(s).', $exception->getMessage());
        }

        $this->assertSame([
            self::FIRST => [false, 0, null],
            self::SECOND => [false, 0, null],
        ], $this->outboxState());
        $this->assertDatabaseCount('delivery_attempts', 0, 'ops');
    }

    public function test_each_context_relays_only_its_own_outbox(): void
    {
        $broker = $this->fakePublisher();
        $this->recordInCms(self::FIRST, 1);
        $booking = Envelopes::availabilityChanged(self::EXPERIENCE_ID);
        OutboxTable::insert(DB::connection('core'), $booking);

        $published = $this->relay('core')->relayBatch();

        $this->assertSame(1, $published);
        $this->assertSame([$booking->eventId], $broker->confirmedEventIds());
        $this->assertSame(['bookings.availability.changed.v1'], $broker->confirmedRoutingKeys());
        $this->assertSame(1, DB::connection('cms')->table('outbox_messages')->whereNull('published_at')->count());
    }

    private function relay(string $context): OutboxRelay
    {
        return $this->app->make(Outboxes::class)->relay($context);
    }

    private function recordInCms(string $eventId, int $aggregateVersion): Envelope
    {
        $published = Envelopes::experiencePublished(self::EXPERIENCE_ID, $aggregateVersion);
        $envelope = new Envelope(
            $eventId, $published->eventName, $published->eventVersion, $published->occurredAt, $published->aggregateType,
            $published->aggregateId, $aggregateVersion, $published->correlationId, null, 'fellwise-cms', $published->payload,
        );
        OutboxTable::insert(DB::connection('cms'), $envelope);

        return $envelope;
    }

    /**
     * @return array<string, array{bool, int, ?string}> id => [published?, attempts, last_error]
     */
    private function outboxState(): array
    {
        return DB::connection('cms')->table('outbox_messages')->orderBy('id')->get()
            ->mapWithKeys(fn (object $row): array => [$row->id => [$row->published_at !== null, (int) $row->publish_attempts, $row->last_error]])
            ->all();
    }
}
