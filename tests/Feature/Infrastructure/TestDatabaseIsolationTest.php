<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Proves the per-lane test-DB isolation harness (tests/bootstrap.php +
 * Tests\TestCase guard). The suite must resolve to a throwaway hfc_dash_test
 * schema for THIS worktree and never to a real/dev database.
 *
 * Portable across lanes: asserts the whitelist, not a specific lane's number,
 * so the same test passes in dev-1 (_test), dev-2 (_test_2), dev-3 (_test_3).
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_suite_connects_to_a_whitelisted_throwaway_schema(): void
    {
        $configured = DB::connection()->getDatabaseName();
        $live = DB::selectOne('SELECT DATABASE() AS db')->db;

        // Fail loudly in the run output so the routing is auditable.
        fwrite(STDERR, PHP_EOL."[test-db-isolation] configured={$configured} live={$live}".PHP_EOL);

        $whitelist = '/^hfc_dash_test(_[0-9]+)?$/';

        $this->assertMatchesRegularExpression(
            $whitelist,
            $configured,
            "Tests must run on a throwaway hfc_dash_test[_N] schema, got '{$configured}'."
        );
        $this->assertMatchesRegularExpression(
            $whitelist,
            $live,
            "Live MySQL connection landed on '{$live}', not a test schema."
        );
        $this->assertSame(
            $configured,
            $live,
            'Configured DB and live connection disagree — env routing is broken.'
        );
        $this->assertStringStartsNotWith('corex_dev', $live, 'Refusing: connected to a dev database.');
    }
}
