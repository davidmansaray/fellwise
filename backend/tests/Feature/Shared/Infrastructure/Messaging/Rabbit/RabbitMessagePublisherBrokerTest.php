<?php

declare(strict_types=1);

namespace Tests\Feature\Shared\Infrastructure\Messaging\Rabbit;

use Fellwise\Shared\Infrastructure\Messaging\Rabbit\AmqpConnectionFactory;
use Fellwise\Shared\Infrastructure\Messaging\Rabbit\RabbitMessagePublisher;
use PHPUnit\Framework\Attributes\Group;
use Tests\Support\Envelopes;
use Tests\TestCase;
use Throwable;

/**
 * Round trip against a real broker on the isolated `fellwise_test` vhost
 * (scripts/broker-test-vhost.sh loads infra/rabbitmq/definitions.json into it).
 * Uses its own exclusive, server-named queue and a routing key no application
 * queue binds, so it can never deliver into (or consume from) real queues. Skips when no broker is configured
 * (BROKER_TEST_PORT / BROKER_TEST_PASSWORD), e.g. on a laptop without the tunnel.
 */
#[Group('broker')]
final class RabbitMessagePublisherBrokerTest extends TestCase
{
    public function test_a_confirmed_persistent_publish_arrives_with_its_envelope_and_headers(): void
    {
        $factory = new AmqpConnectionFactory([
            'host' => (string) (getenv('BROKER_TEST_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('BROKER_TEST_PORT') ?: 5672),
            'user' => (string) (getenv('BROKER_TEST_USER') ?: 'fellwise'),
            'password' => (string) (getenv('BROKER_TEST_PASSWORD') ?: 'fellwise'),
            'vhost' => 'fellwise_test',
            'heartbeat' => 0,
        ]);

        try {
            $channel = $factory->connection()->channel();
        } catch (Throwable $exception) {
            $this->markTestSkipped('No RabbitMQ broker reachable: '.$exception->getMessage());
        }

        [$queue] = $channel->queue_declare('', false, false, true, true);
        $channel->queue_bind($queue, 'fellwise.events', 'tests.broker.round-trip');

        $envelope = Envelopes::experiencePublished('01K74ZQ9M2H3J4K5M6N7P8Q9RS', 1);
        $publisher = new RabbitMessagePublisher($factory, 'fellwise.events');
        $publisher->publish($envelope, ['x-replay' => true], routingKey: 'tests.broker.round-trip');
        $publisher->waitForConfirms();

        $message = null;
        for ($i = 0; $i < 20 && $message === null; $i++) {
            $message = $channel->basic_get($queue, true);
            usleep(50_000);
        }

        $this->assertNotNull($message);
        $this->assertSame($envelope->eventId, $message->get('message_id'));
        $this->assertSame($envelope->toJson(), $message->getBody());
        $this->assertTrue($message->get('application_headers')->getNativeData()['x-replay']);

        $factory->close();
    }
}
