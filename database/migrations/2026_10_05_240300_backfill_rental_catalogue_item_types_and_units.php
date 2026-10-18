<?php

use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Pastel-style enhancement, 2026-10-05 — one-time seed + backfill, same
 * shape as 2026_10_05_230600 (RentalVatType's own existing-agency seed).
 *
 * 1. Every existing, non-archived agency gets the Labour/Part types and the
 *    eleven default units — new agencies get the same via AgencyCreated
 *    (SeedDefaultRentalCatalogueTypesAndUnits).
 * 2. Every existing rental_catalogue_items row is mapped onto the new FKs:
 *    `type` (labour/part) -> the matching seeded type; `unit` (free text)
 *    -> a case-insensitive match against the seeded unit names, or — per
 *    Johan's "migrate existing units onto it" instruction — a new
 *    agency-owned unit row created from that exact free text when nothing
 *    seeded matches, so no existing data is lost or silently renamed.
 *
 * On QA1 this table is fixture data (BUILD_STANDARD Standard -1q) — the
 * mapping logic below is still written to be correct for real agency data,
 * since this same migration runs on Staging/live.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agencyIds = DB::table('agencies')->whereNull('deleted_at')->pluck('id');
        foreach ($agencyIds as $agencyId) {
            RentalCatalogueItemType::seedDefaultsFor((int) $agencyId);
            RentalCatalogueUnit::seedDefaultsFor((int) $agencyId);
        }

        $items = DB::table('rental_catalogue_items')->select('id', 'agency_id', 'type', 'unit')->get();

        foreach ($items as $item) {
            $typeRow = DB::table('rental_catalogue_item_types')
                ->where('agency_id', $item->agency_id)->where('kind', $item->type)
                ->orderBy('sort_order')->first();
            // An agency created after this migration started (race) or a row
            // whose agency was archived mid-backfill wouldn't have been
            // seeded above — seed it now rather than leaving the FK null.
            if (! $typeRow) {
                RentalCatalogueItemType::seedDefaultsFor((int) $item->agency_id);
                $typeRow = DB::table('rental_catalogue_item_types')
                    ->where('agency_id', $item->agency_id)->where('kind', $item->type)
                    ->orderBy('sort_order')->first();
            }

            $unitName = trim((string) $item->unit);
            $unitRow = $unitName !== ''
                ? DB::table('rental_catalogue_units')
                    ->where('agency_id', $item->agency_id)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($unitName)])
                    ->first()
                : null;

            if (! $unitRow && $unitName !== '') {
                $newUnitId = DB::table('rental_catalogue_units')->insertGetId([
                    'agency_id' => $item->agency_id, 'name' => $unitName, 'is_active' => true,
                    'sort_order' => (int) (DB::table('rental_catalogue_units')->where('agency_id', $item->agency_id)->max('sort_order') ?? 0) + 1,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $unitRow = (object) ['id' => $newUnitId];
            }

            DB::table('rental_catalogue_items')->where('id', $item->id)->update([
                'rental_catalogue_item_type_id' => $typeRow?->id,
                'rental_catalogue_unit_id' => $unitRow?->id,
            ]);
        }
    }

    public function down(): void
    {
        // Irreversible by design — the seeded types/units stay (an agency may
        // already have renamed/archived/added to them by the time anyone
        // rolls this back); the FK backfill on rental_catalogue_items is
        // cleared so a re-run of up() is safe.
        DB::table('rental_catalogue_items')->update([
            'rental_catalogue_item_type_id' => null,
            'rental_catalogue_unit_id' => null,
        ]);
    }
};
