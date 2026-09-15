<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-398 — fix for a defect cc1's browser pass found: the original AT-398
 * migration (2026_09_10_100000_create_deal_properties_table) backfilled
 * every pre-existing deal's `is_primary` row but never set its
 * `allocated_price`/`allocated_commission` — so an old, single-property
 * deal showed R0.00 on the new per-property price card even though the
 * deal's own (authoritative, unaffected) property_value/total_commission
 * was correct all along. This is display staleness on the new card only,
 * never a real pricing error — but it reads as broken the moment Johan
 * opens any deal that predates this feature.
 *
 * Applies the exact same mirroring rule already built for the
 * single-property case (Deal::syncPrimaryPropertyPivot()'s count<=1
 * branch): the primary property's allocation = the deal's own totals.
 * Scoped to `is_primary = 1 AND allocated_price IS NULL` — precisely the
 * rows the original backfill left incomplete — so this is naturally
 * idempotent: re-running finds nothing left to correct.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $affected = DB::update(<<<'SQL'
                UPDATE deal_properties dp
                JOIN deals d ON d.id = dp.deal_id
                SET dp.allocated_price = d.property_value,
                    dp.allocated_commission = d.total_commission
                WHERE dp.is_primary = 1
                  AND dp.allocated_price IS NULL
                  AND dp.deleted_at IS NULL
                  AND d.deleted_at IS NULL
            SQL);

            fwrite(STDOUT, "  AT-398 backfill: corrected {$affected} pre-existing deal_properties row(s) with a NULL allocated_price.\n");
        });
    }

    public function down(): void
    {
        // No-op — reverting would mean re-blanking real prices with no way to
        // distinguish "backfilled by this migration" from "genuinely entered
        // since". Never destroy correct data to satisfy a rollback path.
    }
};
