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

        // No feature test worktree ever needs `npm run build` — a Blade
        // view's @vite() directive would otherwise throw
        // ViteManifestNotFoundException the instant a test renders one,
        // which has nothing to do with that test's own code. This used to
        // be ~90 individual test files each calling withoutVite()
        // themselves; fixed at the class here instead (BUILD_STANDARD §6)
        // so every feature test is covered automatically, including ones
        // that don't know yet that they'll ever render a view.
        $this->withoutVite();

        // Reset the per-request permission memo between tests. PermissionService caches
        // `$seeded` (= "role_permissions has any row") as a process-static; without this
        // reset, a test that seeds a role_permission flips it true for the REST of the
        // process, so later tests relying on the "unseeded → allow-all" fallback are
        // denied (403). Clearing here fixes the whole class of cross-test permission
        // pollution, not one instance (BUILD_STANDARD §6). Safe: it only forces the
        // next check to re-read from the (RefreshDatabase-reset) DB.
        \App\Services\PermissionService::clearCache();

        // Several request handlers (PDF splitter, contact import, sold-property import, wet-ink signing...) call
        // set_time_limit(120|300) so a long web request is not cut off. Under the CLI that call RESTARTS the process
        // clock with that limit, and it then applies to the whole rest of the run - a long suite died with "Maximum
        // execution time of 300 seconds exceeded" a few minutes after the first test that touched one. The clock is
        // lifted at the start of every test (the CLI default is "no limit"); the web handlers are untouched.
        @set_time_limit(0);

        // AuditContext is process-static: a test that signed in as someone (or a console command that stamped a source) leaves that
        // user id behind, and the next test's audit rows then point at a user that no longer exists (FK 1452 - swallowed by the
        // audit writer, so the row silently never appears). Reset it at the start of every test, like the permission cache above.
        \App\Support\Audit\AuditContext::reset();
    }
}
