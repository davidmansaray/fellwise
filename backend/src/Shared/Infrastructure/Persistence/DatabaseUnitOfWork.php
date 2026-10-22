<?php

declare(strict_types=1);

namespace Fellwise\Shared\Infrastructure\Persistence;

use Fellwise\Shared\Application\UnitOfWork;
use Illuminate\Database\ConnectionInterface;

final readonly class DatabaseUnitOfWork implements UnitOfWork
{
    public function __construct(private ConnectionInterface $connection) {}

    public function run(callable $work): mixed
    {
        return $this->connection->transaction(fn () => $work(), attempts: 3);
    }
}
