<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Rabbit;

use Illuminate\Http\Client\Factory as Http;
use Throwable;

/**
 * Read-only view of queue depths and rates from the management API.
 */
final readonly class BrokerManagementClient
{
    public function __construct(
        private Http $http,
        private string $baseUrl,
        private string $user,
        private string $password,
        private string $vhost,
    ) {}

    /**
     * @return array<string, array{ready: int, unacked: int, consumers: int, publish_rate: float, deliver_rate: float}>|null null when the broker is unreachable
     */
    public function queues(): ?array
    {
        try {
            $response = $this->http
                ->withBasicAuth($this->user, $this->password)
                ->timeout(2)
                ->get(rtrim($this->baseUrl, '/').'/api/queues/'.rawurlencode($this->vhost), [
                    'columns' => 'name,messages_ready,messages_unacknowledged,consumers,message_stats',
                ]);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $queues = [];

        foreach ((array) $response->json() as $queue) {
            if (! is_array($queue) || ! isset($queue['name'])) {
                continue;
            }

            $stats = is_array($queue['message_stats'] ?? null) ? $queue['message_stats'] : [];
            $queues[(string) $queue['name']] = [
                'ready' => (int) ($queue['messages_ready'] ?? 0),
                'unacked' => (int) ($queue['messages_unacknowledged'] ?? 0),
                'consumers' => (int) ($queue['consumers'] ?? 0),
                'publish_rate' => (float) ($stats['publish_details']['rate'] ?? 0),
                'deliver_rate' => (float) ($stats['deliver_get_details']['rate'] ?? 0),
            ];
        }

        return $queues;
    }

    /**
     * Drop every message in a queue. Used by the install command only.
     */
    public function purge(string $queue): bool
    {
        try {
            return $this->http
                ->withBasicAuth($this->user, $this->password)
                ->timeout(5)
                ->delete(rtrim($this->baseUrl, '/').'/api/queues/'.rawurlencode($this->vhost).'/'.rawurlencode($queue).'/contents')
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }
}
