<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Infrastructure\Messaging\Rabbit;

use Fellwise\Shared\Domain\Messaging\Envelope;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\AmqpConnectionFactory;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\ParkingLot;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RabbitConsumer;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RabbitMessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\FaultInjector;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\RefreshContexts;
use Tests\Support\Envelopes;
use Tests\TestCase;
use Throwable;

/**
 * Proves ADR-003 against a real RabbitMQ: the consumer acks a failed
 * message only after it is safely in the retry tier, the tier's TTL
 * dead-letters it back to the owning queue, and unparking resets its budget.
 *
 * Runs on the isolated `fellwise_test` vhost (same definitions.json) so it
 * can never touch live queues. Opt in: BROKER_TEST_PORT / BROKER_TEST_PASSWORD
 * and `--group broker`.
 */
#[Group('broker')]
final class RetryTopologyBrokerTest extends TestCase
{
    use RefreshContexts;

    private const string QUEUE = 'core.catalogue';

    private AmqpConnectionFactory $connections;

    private AMQPChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connections = new AmqpConnectionFactory([
            'host' => (string) (getenv('BROKER_TEST_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('BROKER_TEST_PORT') ?: 5672),
            'user' => (string) (getenv('BROKER_TEST_USER') ?: 'fellwise'),
            'password' => (string) (getenv('BROKER_TEST_PASSWORD') ?: 'fellwise'),
            'vhost' => 'fellwise_test',
            'heartbeat' => 0,
        ]);

        try {
            $this->channel = $this->connections->connection()->channel();
        } catch (Throwable $exception) {
            $this->markTestSkipped('No RabbitMQ test vhost reachable: '.$exception->getMessage());
        }

        foreach ([self::QUEUE, self::QUEUE.'.retry.5s', self::QUEUE.'.retry.15s', self::QUEUE.'.retry.45s', self::QUEUE.'.parking-lot'] as $queue) {
            $this->channel->queue_purge($queue);
        }

        $this->app->instance(AmqpConnectionFactory::class, $this->connections);
        $this->app->instance(MessagePublisher::class, new RabbitMessagePublisher($this->connections, 'fellwise.events'));
    }

    protected function tearDown(): void
    {
        $this->connections->close();

        parent::tearDown();
    }

    public function test_a_failure_is_moved_to_the_first_tier_before_the_original_is_acked_and_comes_back_after_its_ttl(): void
    {
        $this->app->make(FaultInjector::class)->set(self::QUEUE, true);
        $envelope = Envelopes::experiencePublished('01K74ZQ9M2H3J4K5M6N7P8Q9RS', 1);
        $this->publish($envelope);

        $this->app->make(RabbitConsumer::class)->consume(self::QUEUE, 1, maxMessages: 1);

        $this->assertNull($this->channel->basic_get(self::QUEUE, true), 'The original must be acked, not left on the main queue.');
        $retried = $this->await(self::QUEUE.'.retry.5s', 2);
        $this->assertSame($envelope->eventId, $retried->get('message_id'));
        $this->assertSame(1, $this->headers($retried)['x-retry-count']);
        $this->assertStringContainsString('Connection refused', (string) $this->headers($retried)['x-last-error']);

        // Put it back so the TTL can dead-letter it through fellwise.requeue.
        $this->channel->basic_publish($retried, 'fellwise.retry', self::QUEUE.'.retry.5s');
        $returned = $this->await(self::QUEUE, 9);
        $this->assertSame($envelope->eventId, $returned->get('message_id'));
        $this->assertSame(1, $this->headers($returned)['x-retry-count'], 'Back on the owning queue with its retry count intact.');
    }

    public function test_unparking_returns_a_message_to_its_queue_with_a_fresh_retry_budget(): void
    {
        $envelope = Envelopes::experiencePublished('01K74ZQ9M2H3J4K5M6N7P8Q9RS', 1);
        $publisher = $this->app->make(MessagePublisher::class);
        $publisher->publish($envelope, ['x-retry-count' => 3, 'x-last-error' => 'boom'], 'fellwise.parking', self::QUEUE);
        $publisher->waitForConfirms();
        $this->await(self::QUEUE.'.parking-lot', 2, keep: true);

        $moved = $this->app->make(ParkingLot::class)->unpark(self::QUEUE);

        $this->assertSame(1, $moved);
        $this->assertNull($this->channel->basic_get(self::QUEUE.'.parking-lot', true));
        $unparked = $this->await(self::QUEUE, 2);
        $this->assertSame($envelope->eventId, $unparked->get('message_id'));
        $this->assertSame(0, $this->headers($unparked)['x-retry-count']);
        $this->assertArrayHasKey('x-unparked-at', $this->headers($unparked));
    }

    private function publish(Envelope $envelope): void
    {
        $publisher = $this->app->make(MessagePublisher::class);
        $publisher->publish($envelope);
        $publisher->waitForConfirms();
    }

    private function await(string $queue, int $seconds, bool $keep = false): AMQPMessage
    {
        $deadline = microtime(true) + $seconds;

        do {
            $message = $this->channel->basic_get($queue, ! $keep);

            if ($message instanceof AMQPMessage) {
                if ($keep) {
                    $message->nack(true);
                }

                return $message;
            }

            usleep(100_000);
        } while (microtime(true) < $deadline);

        $this->fail("Nothing arrived on {$queue} within {$seconds}s.");
    }

    /**
     * @return array<string, mixed>
     */
    private function headers(AMQPMessage $message): array
    {
        /** @var array<string, mixed> */
        return $message->get('application_headers')->getNativeData();
    }
}
