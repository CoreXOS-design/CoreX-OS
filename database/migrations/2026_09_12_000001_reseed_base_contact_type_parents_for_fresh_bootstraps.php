<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-392, 2026-09-12 — closes the test-infrastructure hole found while
 * building the contact-type picker fix the day before.
 *
 * `php artisan schema:dump` (Illuminate\Database\Schema\MySqlSchemaState::dump())
 * ALWAYS runs mysqldump with `--no-data` — it captures structure only, plus
 * (separately, by design) the `migrations` table's own row data so Laravel
 * knows what's "already applied". It never captures any other table's rows.
 *
 * The 6 base ContactType parents (Seller/Buyer/Lessor/Lessee/Owner/Other) are
 * created by two existing, genuinely idempotent migrations —
 * 2026_07_02_000003_seed_canonical_contact_parents_and_backfill_pivot and
 * 2026_07_03_000001_seed_owner_other_contact_parents — but both are already
 * baked into database/schema/mysql-schema.sql's migrations-table dump as
 * "already run". Laravel's migrator trusts that ledger and skips a migration
 * entirely (never executes its body) once it sees the filename there — so on
 * ANY fresh RefreshDatabase test bootstrap (which loads the snapshot instead
 * of replaying history), those two migrations' idempotent "insert if missing"
 * logic never runs, and the schema:dump structure-only rule means the rows
 * they'd have inserted were never captured either way. Net effect: every
 * fresh test database starts with ZERO of the 6 base parents, and every test
 * that resolves one by esign_role or name (ContactType::canonical(),
 * ->parents(), ::where('esign_role', ...)) fails with ModelNotFoundException
 * or an empty collection — regardless of whether the code under test is
 * correct. Confirmed by an entirely untouched, pre-existing test in
 * ContactTypeAssignmentTest failing identically to changed ones.
 *
 * This migration is dated AFTER the current schema-dump baseline, so — unlike
 * the two migrations above — it actually executes on a fresh test bootstrap.
 * Same idempotent "insert only if missing" shape as
 * 2026_09_11_000001_seed_tenant_contact_type_if_missing, matching QA1's real,
 * live values exactly so it is a true no-op on every already-seeded
 * environment (QA1, Staging, live, demo) — it only does real work on a
 * genuinely fresh database (a new agency, or a test's schema-snapshot
 * bootstrap).
 *
 * Whoever next regenerates the schema snapshot should be aware regenerating
 * it does NOT fix this class of gap — schema:dump structural no-data-ness is
 * permanent, not a staleness problem. The durable fix for any data-seeding
 * migration going forward is this pattern: a dated, idempotent "insert if
 * missing" migration, never a bare seeder nobody re-runs.
 */
return new class extends Migration
{
    /** name => [esign_role, color, sort_order] — QA1's real, live values. */
    private array $baseParents = [
        'Seller' => ['seller', '#e67e22', 1],
        'Buyer'  => ['buyer',  '#27ae60', 2],
        'Lessor' => ['lessor', '#6366f1', 3],
        'Lessee' => ['lessee', '#6366f1', 4],
        'Owner'  => [null,     '#6366f1', 0],
        'Other'  => [null,     '#6366f1', 0],
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->baseParents as $name => [$esignRole, $color, $sortOrder]) {
            $exists = DB::table('contact_types')
                ->whereNull('deleted_at')
                ->where('name', $name)
                ->where(function ($q) use ($esignRole) {
                    $esignRole === null ? $q->whereNull('esign_role') : $q->where('esign_role', $esignRole);
                })
                ->exists();

            if (! $exists) {
                DB::table('contact_types')->insert([
                    'name'       => $name,
                    'esign_role' => $esignRole,
                    'color'      => $color,
                    'is_active'  => 1,
                    'sort_order' => $sortOrder,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive up() — nothing to reverse. Real environments already
        // had these rows before this migration ever ran; a fresh test database
        // has no meaningful "before" state to restore.
    }
};
