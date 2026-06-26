<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Authoritative-verification stamp for P24 suburbs.
 *
 * `p24_verified_at` records that this exact `p24_id` was returned by P24 under
 * this `p24_city_id` in the last authoritative location sync/reconcile. It is
 * the single signal that distinguishes a P24-confirmed suburb from a stale or
 * phantom row (e.g. Addington/5997, which P24 never returned — see
 * `.ai/audits/at104-p24-suburb-resolution-audit-2026-06-26.md`).
 *
 * Set by:
 *   - `p24:sync-locations`   (every updateOrCreate stamps now())
 *   - `p24:reconcile-suburbs` (live diff: stamp survivors, soft-delete the rest)
 *
 * Consumed by:
 *   - AppliesP24Location  — a property may only land on a verified suburb
 *   - P24LocationController::suburbs — only verified rows are offered in the cascade
 *
 * Backfill: every existing non-deleted row is stamped with its `updated_at`
 * (falling back to now()) so live property saves keep working immediately. The
 * server-side `p24:reconcile-suburbs --apply` then re-stamps from the live P24
 * list and soft-deletes anything P24 no longer recognises — at which point the
 * stamp means "present in the latest live sync" for the whole table.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('p24_suburbs')) {
            return;
        }

        if (!Schema::hasColumn('p24_suburbs', 'p24_verified_at')) {
            Schema::table('p24_suburbs', function (Blueprint $table) {
                $table->timestamp('p24_verified_at')->nullable()->after('confirmed')->index();
            });
        }

        // Backfill: trust current non-deleted rows so the AppliesP24Location
        // guard does not break existing property saves before the live
        // reconcile runs on the server. Stale rows are corrected/removed there.
        DB::statement("
            UPDATE p24_suburbs
            SET p24_verified_at = COALESCE(updated_at, NOW())
            WHERE deleted_at IS NULL
              AND p24_verified_at IS NULL
        ");
    }

    public function down(): void
    {
        if (Schema::hasColumn('p24_suburbs', 'p24_verified_at')) {
            Schema::table('p24_suburbs', function (Blueprint $table) {
                $table->dropColumn('p24_verified_at');
            });
        }
    }
};
