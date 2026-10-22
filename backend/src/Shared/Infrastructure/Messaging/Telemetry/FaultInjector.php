<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Messaging\Telemetry;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Chaos switch for the console. When `fault.{queue}` is on, that consumer's
 * handlers fail as if their database were down, which exercises the real
 * retry/parking path end to end. Flags are re-read at most once a second.
 */
final class FaultInjector
{
    public const string TABLE = 'ops_flags';

    /** @var array<string, array{0: bool, 1: float}> */
    private array $cache = [];

    public function __construct(private readonly ConnectionInterface $ops) {}

    public function isFailing(string $queue): bool
    {
        [$value, $readAt] = $this->cache[$queue] ?? [false, 0.0];

        if (microtime(true) - $readAt > 1.0) {
            $flag = $this->ops->table(self::TABLE)->where('key', "fault.{$queue}")->first();
            $value = (bool) ($flag->enabled ?? false);

            if ($flag !== null && $value && config('demo.enabled') && ($flag->updated_at === null || Carbon::parse($flag->updated_at)->lte(now()->subSeconds((int) config('demo.fault_seconds'))))) {
                $this->ops->table(self::TABLE)->where('key', "fault.{$queue}")
                    ->where('updated_at', $flag->updated_at)->update(['enabled' => false]);
                $value = false;
            }
            $this->cache[$queue] = [$value, microtime(true)];
        }

        return $value;
    }

    public function set(string $queue, bool $enabled): void
    {
        $this->ops->table(self::TABLE)->upsert(
            [['key' => "fault.{$queue}", 'enabled' => $enabled, 'updated_at' => now()]],
            ['key'],
            ['enabled', 'updated_at'],
        );
        unset($this->cache[$queue]);
    }

    public function throwIfFailing(string $queue): void
    {
        if ($this->isFailing($queue)) {
            throw new RuntimeException('SQLSTATE[HY000] [2002] Connection refused (read model primary unavailable)');
        }
    }
}
