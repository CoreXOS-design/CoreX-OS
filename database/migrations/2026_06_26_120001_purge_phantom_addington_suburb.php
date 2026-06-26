<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purge the known phantom suburb (Addington / p24_id 5997) and remediate any
 * property left pointing at it.
 *
 * The audit (.ai/audits/at104-p24-suburb-resolution-audit-2026-06-26.md) proved
 * Addington/5997 exists nowhere in P24's KZN hierarchy — P24 rejects
 * `suburbId=5997` with HTTP 400 "SuburbId is invalid". It is a stale local row
 * that slipped past AppliesP24Location's chain check. The broad reconcile
 * (`p24:reconcile-suburbs`) removes the rest of the stale universe from the
 * live P24 diff; this migration handles the one proven phantom deterministically
 * (no P24 credentials needed) so every environment is clean on deploy.
 *
 * Matched by (p24_id, name) — NOT by local row id, which differs per environment.
 * Idempotent: a second run finds nothing to do. No hard delete (non-negotiable #1).
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('p24_suburbs')) {
            return;
        }

        $phantomIds = DB::table('p24_suburbs')
            ->where('p24_id', 5997)
            ->whereRaw('LOWER(TRIM(name)) = ?', ['addington'])
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        if (empty($phantomIds)) {
            return; // already purged / never present
        }

        // Remediate properties still pinned to the phantom: drop the bad FK chain
        // and flag for re-pick. We do NOT guess a replacement — Addington is not
        // a P24 suburb, so the agent must choose a real P24-recognised suburb.
        if (Schema::hasColumn('properties', 'p24_suburb_id')) {
            $update = ['p24_suburb_id' => null];
            if (Schema::hasColumn('properties', 'p24_city_id')) {
                $update['p24_city_id'] = null;
            }
            if (Schema::hasColumn('properties', 'p24_province_id')) {
                $update['p24_province_id'] = null;
            }
            if (Schema::hasColumn('properties', 'p24_suburb_mismatch')) {
                $update['p24_suburb_mismatch'] = 1;
            }

            DB::table('properties')
                ->whereIn('p24_suburb_id', $phantomIds)
                ->update($update);
        }

        // Soft-delete the phantom suburb row(s).
        DB::table('p24_suburbs')
            ->whereIn('id', $phantomIds)
            ->update(['deleted_at' => now(), 'p24_verified_at' => null]);
    }

    public function down(): void
    {
        // Non-destructive: a phantom row is not worth resurrecting on rollback.
    }
};
