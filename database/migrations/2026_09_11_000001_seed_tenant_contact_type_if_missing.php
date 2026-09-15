<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Contact-type ruling, 2026-09-11 — AddTenantTypeOnRentalApproval depends on
 * exactly one canonical "Tenant" ContactType row existing (the same row
 * Property24/website lead capture already resolve via
 * `ContactType::where('name', 'Tenant')->value('id')`). That row already
 * exists on QA1 (id 11, esign_role 'lessee', sort_order 0, seeded outside
 * the migration history at some point) but no migration actually creates
 * it — confirmed by grepping every migration for the literal string before
 * writing this — so a genuinely fresh environment (a new agency's DB, or
 * this feature's own test bootstrap) would have no such row at all, and
 * the listener would silently no-op every approval (safe, but the feature
 * Johan asked for would just never fire). Same idempotent
 * "insert only if the exact row is missing" pattern as the sibling
 * 2026_07_03_000001_seed_owner_other_contact_parents migration, matching
 * QA1's existing values exactly so this is a true no-op there.
 */
return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('contact_types')
            ->whereNull('deleted_at')
            ->where('name', 'Tenant')
            ->where('esign_role', 'lessee')
            ->exists();

        if (! $exists) {
            $now = now();
            DB::table('contact_types')->insert([
                'name' => 'Tenant',
                'esign_role' => 'lessee',
                'color' => '#6366f1',
                'is_active' => 1,
                'sort_order' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Soft-remove only if this migration's own up() created it — never
        // touch a pre-existing row (e.g. QA1's, which predates this
        // migration and carries real contact associations).
    }
};
