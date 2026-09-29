<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * §22 ruling (Johan, 2026-09-29) — before this build, EVERY property-level
 * inventory's owner-side signature (whether the property was a sale or a
 * rental currently between tenancies) was recorded with `party_role =
 * 'landlord'` — see RentalInventory::outstandingSignatories()'s pre-§18
 * form. A sale property's owner should have been recorded as 'seller' all
 * along (RentalInventorySignature::PARTY_SELLER, added this same build).
 * This is a pure data correction: relabels existing rows, changes no
 * behaviour going forward (the model/controller changes in this same
 * commit make new sale-inventory rows record 'seller' directly).
 *
 * Scope, deliberately narrow — exactly Johan's own words: "only where
 * lease_id is null AND the property is a sale property." A property-level
 * inventory on a RENTAL property between tenancies (lease_id also null)
 * must NOT be touched here — it stays 'landlord', correctly, both before
 * and after this migration; `properties.listing_type = 'sale'`
 * (Property::LISTING_TYPES) is the one field that tells the two apart.
 *
 * Idempotent — re-running finds zero matching rows the second time (they
 * already read 'seller'), and the report_counts log line only ever grows
 * more precise, never wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        $matchingIds = function () {
            return DB::table('rental_inventory_signatures as ris')
                ->join('rental_inventories as ri', 'ri.id', '=', 'ris.rental_inventory_id')
                ->join('properties as p', 'p.id', '=', 'ri.property_id')
                ->where('ris.party_role', 'landlord')
                ->whereNull('ri.lease_id')
                ->where('p.listing_type', 'sale')
                ->pluck('ris.id');
        };

        $ids = $matchingIds();

        if ($ids->isNotEmpty()) {
            DB::table('rental_inventory_signatures')
                ->whereIn('id', $ids)
                ->update(['party_role' => 'seller']);
        }

        Log::info("[2026_10_06_090000] relabelled {$ids->count()} rental_inventory_signatures row(s) from 'landlord' to 'seller' (sale property, no lease).");
    }

    public function down(): void
    {
        // Deliberately no-op. Reversing would require re-deriving "which of
        // these 'seller' rows were originally 'landlord' before this
        // migration ran" — information this migration does not preserve
        // (and should not: 'seller' is the CORRECT value for these rows,
        // not a temporary state). A rollback that silently mislabels a
        // sale's seller back to 'landlord' would be a worse data state than
        // leaving the correction in place.
    }
};
