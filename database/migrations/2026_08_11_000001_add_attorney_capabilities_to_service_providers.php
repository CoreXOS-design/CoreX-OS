<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-364 — a supplier firm can be BOTH a transfer attorney AND a bond attorney.
 *
 * The legacy `specialty` column is single-valued (one ENUM value per row), so a firm
 * saved as `transfer_attorney` could never surface in the `bond_attorney` picker (both
 * pickers filter `where('specialty', …)`). Firms like BBB do both — they must be
 * selectable as either.
 *
 * DESIGN: additive capability booleans, NOT a specialty-set rewrite. The two attorney
 * roles are FIXED (unlike the agency-configurable AT-319 service-types), so booleans are
 * the cleanest fit and leave the `specialty` enum — which the distribution/recipient
 * resolver and dedup key off — completely untouched. The pickers OR the capability with
 * the legacy specialty, so nothing that surfaced before disappears.
 *
 * Backfill: existing attorney rows keep working immediately — a `transfer_attorney` row
 * gets `is_transfer_attorney = 1`, a `bond_attorney` row gets `is_bond_attorney = 1`.
 * Additive only; no enum change, no drops, no effect on the DR distribution math.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_service_providers', function (Blueprint $table) {
            $table->boolean('is_transfer_attorney')->default(false)->after('specialty');
            $table->boolean('is_bond_attorney')->default(false)->after('is_transfer_attorney');
            // The picker filters on (capability OR legacy specialty), agency-scoped, active only.
            $table->index(['agency_id', 'is_transfer_attorney', 'is_active'], 'asp_transfer_cap_idx');
            $table->index(['agency_id', 'is_bond_attorney', 'is_active'], 'asp_bond_cap_idx');
        });

        // Backfill from the legacy single specialty so existing attorney firms surface at once.
        DB::table('agency_service_providers')->where('specialty', 'transfer_attorney')->update(['is_transfer_attorney' => true]);
        DB::table('agency_service_providers')->where('specialty', 'bond_attorney')->update(['is_bond_attorney' => true]);
    }

    public function down(): void
    {
        Schema::table('agency_service_providers', function (Blueprint $table) {
            $table->dropIndex('asp_transfer_cap_idx');
            $table->dropIndex('asp_bond_cap_idx');
            $table->dropColumn(['is_transfer_attorney', 'is_bond_attorney']);
        });
    }
};
