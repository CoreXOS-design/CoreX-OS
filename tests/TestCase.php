<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Runtime half of the test-database safety guard (see tests/bootstrap.php).
     *
     * Runs before parent::setUp() triggers any RefreshDatabase work, so a
     * misrouted connection is refused BEFORE a single table can be dropped.
     * The suite may only ever run against a throwaway test schema.
     */
    protected function setUp(): void
    {
        $database = (string) env('DB_DATABASE', '');

        if (! preg_match('/^hfc_dash_test(_[0-9]+)?$/', $database)) {
            $this->fail(
                "[TEST SAFETY GUARD] Refusing to run: DB_DATABASE='{$database}' is not a "
                .'test database. Allowed: hfc_dash_test or hfc_dash_test_<N>. '
                .'Check TEST_DB_DATABASE in this worktree\'s .env.'
            );
        }

        parent::setUp();

        // Reset the per-request permission memo between tests. PermissionService caches
        // `$seeded` (= "role_permissions has any row") as a process-static; without this
        // reset, a test that seeds a role_permission flips it true for the REST of the
        // process, so later tests relying on the "unseeded → allow-all" fallback are
        // denied (403). Clearing here fixes the whole class of cross-test permission
        // pollution, not one instance (BUILD_STANDARD §6). Safe: it only forces the
        // next check to re-read from the (RefreshDatabase-reset) DB.
        \App\Services\PermissionService::clearCache();
    }
}
