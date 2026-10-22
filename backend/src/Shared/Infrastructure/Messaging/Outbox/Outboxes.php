<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Outbox;

use Fellwise\Shared\Infrastructure\Messaging\Rabbit\MessagePublisher;
use Fellwise\Shared\Infrastructure\Messaging\Telemetry\DeliveryLog;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;

/**
 * Resolves the relay/replayer for a producing context's own outbox.
 */
final readonly class Outboxes
{
    /**
     * @param  array<string, array{connection: string, producer: string}>  $producers
     */
    public function __construct(
        private array $producers,
        private DatabaseManager $databases,
        private MessagePublisher $publisher,
        private DeliveryLog $log,
    ) {}

    /**
     * @return list<string>
     */
    public function contexts(): array
    {
        return array_keys($this->producers);
    }

    public function connection(string $context): ConnectionInterface
    {
        $producer = $this->producers[$context] ?? throw new InvalidArgumentException("Unknown producer context [{$context}].");

        return $this->databases->connection($producer['connection']);
    }

    public function relay(string $context): OutboxRelay
    {
        return new OutboxRelay($this->connection($context), $this->publisher, $this->log);
    }

    public function replayer(string $context): OutboxReplayer
    {
        return new OutboxReplayer($this->connection($context), $this->publisher, $this->log);
    }
}
