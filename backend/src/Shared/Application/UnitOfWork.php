<?php

declare(strict_types=1);

namespace Fellwise\Shared\Application;

/**
 * Port: run a use case atomically. Aggregate writes and their outbox
 * entries commit together or not at all.
 */
interface UnitOfWork
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public function run(callable $work): mixed;
}
