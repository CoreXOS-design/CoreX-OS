<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * 2026-08-23 diagnosis — a test DB built via RefreshDatabase's schema-snapshot fast path
     * gets schema + the migrations ledger, never the DATA any migration seeded inline or via
     * a Seeder class. AssistantRoleSeeder's own docblock already named this exact gap for the
     * `assistant` role; deploy:sync-reference-data (AT-162) exists precisely to re-provision
     * this class of data on a real deploy, but nothing ever called it for tests. Confirmed
     * directly: a fresh test DB has zero rows in `roles` and zero in `document_types`.
     * TestReferenceDataSeeder runs that command (plus one additional, evidenced gap it doesn't
     * cover) — this property is Laravel's own sanctioned RefreshDatabase hook
     * (CanConfigureMigrationCommands::seeder()), invoked exactly once per test process,
     * immediately after the fresh migrate. See database/seeders/TestReferenceDataSeeder.php.
     */
    protected $seeder = \Database\Seeders\TestReferenceDataSeeder::class;

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
