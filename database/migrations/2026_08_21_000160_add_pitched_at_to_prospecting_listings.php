<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PITCHED-state transition (Johan 2026-08-14).
 *
 * A prospecting item is PITCHED/WORKED once "Create & continue" commits — a Property is
 * created/linked (matched_property_id) AND ≥1 seller Contact is linked. This timestamp is the
 * COMMITTED marker: it is stamped at that commit only (not while the agent is still using the
 * compose screen as a reversible scratchpad), so the compose route-guard never hijacks a mid-edit
 * reload. `is_pitched = pitched_at IS NOT NULL`.
 *
 * Backfill: existing worked items (matched property + a seller link) are marked pitched now.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->timestamp('pitched_at')->nullable()->after('matched_at');
            $table->index('pitched_at', 'prosp_listings_pitched_idx');
        });

        // Backfill already-worked items: matched property + at least one seller link.
        DB::statement("
            UPDATE prospecting_listings l
            SET l.pitched_at = COALESCE(l.matched_at, l.updated_at)
            WHERE l.matched_property_id IS NOT NULL
              AND l.pitched_at IS NULL
              AND EXISTS (
                  SELECT 1 FROM contact_property cp
                  WHERE cp.property_id = l.matched_property_id AND cp.role = 'seller'
              )
        ");
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('prosp_listings_pitched_idx');
            $table->dropColumn('pitched_at');
        });
    }
};
