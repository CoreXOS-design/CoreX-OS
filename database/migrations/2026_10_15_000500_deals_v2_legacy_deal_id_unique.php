<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * One v2 row per real deal, enforced by the database (2026-10-08, "one source for a deal's
 * money"). deals_v2.legacy_deal_id only had a plain index, so two requests creating the twin of
 * the same deal at once (DealSyncService::ensureTwin) could both succeed — two v2 rows for one
 * deal, each with its own copy of the money. NULL stays allowed any number of times (native v2
 * deals have no real deal); a soft-deleted twin still holds its deal's slot, which is what
 * ensureTwin / the backfill already assume (they look the twin up withoutGlobalScopes).
 *
 * Safe to deploy on data that already has a duplicate: the index is NOT added then (the deploy
 * must not fail halfway on someone's data) — a critical log line is written and
 * `php artisan deals:parity-check` reports every duplicate as FAIL until it is resolved by hand
 * and this migration's index is added.
 */
return new class extends Migration
{
    private const INDEX = 'deals_v2_legacy_deal_id_unique';

    public function up(): void
    {
        if (! Schema::hasTable('deals_v2') || ! Schema::hasColumn('deals_v2', 'legacy_deal_id')) {
            return;
        }

        $dupes = DB::table('deals_v2')->whereNotNull('legacy_deal_id')
            ->groupBy('legacy_deal_id')->havingRaw('COUNT(*) > 1')
            ->pluck('legacy_deal_id');

        if ($dupes->isNotEmpty()) {
            Log::critical('deals_v2.legacy_deal_id unique index NOT added: more than one v2 row is linked to the same deal', [
                'legacy_deal_ids' => $dupes->all(),
            ]);

            return;
        }

        Schema::table('deals_v2', function ($table) {
            $table->unique('legacy_deal_id', self::INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('deals_v2')) {
            return;
        }

        $has = collect(Schema::getIndexes('deals_v2'))->contains(fn ($i) => ($i['name'] ?? null) === self::INDEX);
        if ($has) {
            Schema::table('deals_v2', function ($table) {
                $table->dropUnique(self::INDEX);
            });
        }
    }
};
