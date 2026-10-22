<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Process-wide flag shared by every class that uses RefreshContexts. A static
 * property declared on the trait itself would be per using-class, which would
 * re-run migrate:fresh once per test class instead of once per run.
 */
final class ContextDatabaseState
{
    public static bool $migrated = false;

    /** @var array<string, list<string>> tables to empty before each test, per connection */
    public static array $tables = [];
}
