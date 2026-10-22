<?php

namespace Tests;

use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Fakes\InMemoryMessagePublisher;

abstract class TestCase extends BaseTestCase
{
    /**
     * Swap the AMQP publisher for the in-memory double. Must run before
     * anything resolves Outboxes (a singleton that captures the publisher).
     */
    protected function fakePublisher(): InMemoryMessagePublisher
    {
        $publisher = new InMemoryMessagePublisher((string) config('messaging.exchanges.events'));
        $this->app->instance(MessagePublisher::class, $publisher);

        return $publisher;
    }
}
