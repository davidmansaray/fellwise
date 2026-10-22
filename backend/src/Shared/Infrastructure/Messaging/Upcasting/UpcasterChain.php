<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Upcasting;

use Fellwise\Shared\Application\Messaging\Upcaster;
use Fellwise\Shared\Domain\Messaging\Envelope;

final class UpcasterChain
{
    /** @var array<string, Upcaster> keyed by "{name}@{fromVersion}" */
    private array $upcasters = [];

    /**
     * @param  iterable<Upcaster>  $upcasters
     */
    public function __construct(iterable $upcasters)
    {
        foreach ($upcasters as $upcaster) {
            $this->upcasters[$upcaster->eventName().'@'.$upcaster->fromVersion()] = $upcaster;
        }
    }

    /**
     * @return array{0: Envelope, 1: list<int>} the latest envelope and the versions it passed through
     */
    public function upcast(Envelope $envelope): array
    {
        $path = [];

        while (isset($this->upcasters[$envelope->eventName.'@'.$envelope->eventVersion])) {
            $upcaster = $this->upcasters[$envelope->eventName.'@'.$envelope->eventVersion];
            $path[] = $envelope->eventVersion;
            $envelope = $envelope->withPayload($envelope->eventVersion + 1, $upcaster->upcast($envelope->payload));
        }

        return [$envelope, $path];
    }
}
