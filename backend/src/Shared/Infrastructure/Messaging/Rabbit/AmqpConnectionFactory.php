<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPConnectionConfig;
use PhpAmqpLib\Connection\AMQPConnectionFactory as LibFactory;
use PhpAmqpLib\Connection\Heartbeat\PCNTLHeartbeatSender;

final class AmqpConnectionFactory
{
    private ?AbstractConnection $connection = null;

    private ?PCNTLHeartbeatSender $heartbeat = null;

    /**
     * @param  array{host: string, port: int, user: string, password: string, vhost: string, heartbeat: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function connection(): AbstractConnection
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return $this->connection;
        }

        $config = new AMQPConnectionConfig;
        $config->setHost($this->config['host']);
        $config->setPort($this->config['port']);
        $config->setUser($this->config['user']);
        $config->setPassword($this->config['password']);
        $config->setVhost($this->config['vhost']);
        $config->setHeartbeat($this->config['heartbeat']);
        $config->setConnectionName('fellwise:'.gethostname().':'.getmypid());
        $config->setIsLazy(false);

        $this->heartbeat?->unregister();
        $this->heartbeat = null;
        $this->connection = LibFactory::create($config);

        // Long-lived workers (relay, consumers) can sit idle longer than the
        // heartbeat window; without this the broker drops them silently.
        // SIGALRM-driven, so only in CLI processes, never under FPM.
        if (PHP_SAPI === 'cli' && $this->config['heartbeat'] > 0 && function_exists('pcntl_async_signals')) {
            $this->heartbeat = new PCNTLHeartbeatSender($this->connection);
            $this->heartbeat->register();
        }

        return $this->connection;
    }

    public function close(): void
    {
        $this->heartbeat?->unregister();
        $this->heartbeat = null;

        if ($this->connection?->isConnected()) {
            $this->connection->close();
        }

        $this->connection = null;
    }
}
