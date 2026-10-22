<?php

declare(strict_types=1);

namespace Tests\Concerns;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gives each test empty cms/core/ops schemas without wrapping it in a
 * transaction: the outbox recorder refuses to write at transaction level 0,
 * so a test transaction would hide exactly the bug that guard exists for.
 *
 * Once per run: wipe core + ops and run migrate:fresh (the migrations table
 * lives on cms). Before every later test: empty every non-migration table.
 *
 * Tables are emptied with DELETE rather than TRUNCATE: on MySQL 9 a TRUNCATE
 * recreates the tablespace (seconds per test across ~30 tables), whereas a
 * DELETE on an almost-empty table is sub-millisecond. Nothing in the suite
 * depends on auto-increment values restarting.
 */
trait RefreshContexts
{
    /** @var list<string> */
    protected array $contextConnections = ['cms', 'core', 'ops'];

    protected function setUpRefreshContexts(): void
    {
        $this->assertOnlyTestSchemasAreConfigured();

        if (! ContextDatabaseState::$migrated) {
            foreach (['ops', 'core'] as $connection) {
                Artisan::call('db:wipe', ['--database' => $connection, '--force' => true]);
            }

            Artisan::call('migrate:fresh', ['--database' => 'cms', '--force' => true]);
            ContextDatabaseState::$migrated = true;

            return;
        }

        foreach ($this->contextConnections as $connection) {
            $this->truncateContext($connection);
        }
    }

    private function truncateContext(string $connection): void
    {
        $db = DB::connection($connection);
        $tables = ContextDatabaseState::$tables[$connection] ??= array_values(array_filter(
            $db->getSchemaBuilder()->getTableListing($db->getDatabaseName(), false),
            static fn (string $table): bool => $table !== 'migrations',
        ));

        $db->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                $db->delete("DELETE FROM `{$table}`");
            }
        } finally {
            $db->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /**
     * Destructive set-up must never reach a real schema, whatever a stray
     * environment variable or cached config says.
     */
    private function assertOnlyTestSchemasAreConfigured(): void
    {
        foreach ($this->contextConnections as $connection) {
            $database = (string) config("database.connections.{$connection}.database");

            if (! str_ends_with($database, '_test')) {
                throw new RuntimeException("Refusing to reset [{$connection}] database [{$database}]: test schemas must end in _test.");
            }
        }

        if (config('database.default') !== 'cms') {
            throw new RuntimeException('Tests expect the cms connection to be the default (it owns the migrations table).');
        }
    }
}
