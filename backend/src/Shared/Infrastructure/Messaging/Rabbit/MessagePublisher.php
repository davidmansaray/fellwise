<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

use Fellwise\Shared\Domain\Messaging\Envelope;

interface MessagePublisher
{
    /**
     * Publish to the events exchange (default) or to an explicit exchange
     * and routing key, e.g. a retry tier or the parking lot.
     *
     * @param  array<string, scalar|null>  $headers
     */
    public function publish(Envelope $envelope, array $headers = [], ?string $exchange = null, ?string $routingKey = null): void;

    /**
     * Block until the broker has confirmed everything published so far.
     *
     * @throws \RuntimeException when the broker nacks or times out
     */
    public function waitForConfirms(): void;
}
