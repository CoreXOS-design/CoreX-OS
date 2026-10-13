<?php

use App\Models\DealV2\AgencyServiceProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Johan, 13 Oct 2026 - "service types should be linked to the contractor specialty". A contractor's trade is its ticked service types (Settings -> Service types); the old
 * fixed `specialty` list (plumber, electrician, entomologist, gas, electric_fence) is only kept for the property-sales pickers. Suppliers added before the two were linked carry
 * ONLY the old value, so the rental fault match ("Suits <fault type>") never saw them. This ticks, per agency, the service type that IS the same trade (plumber -> Plumbing,
 * electrician -> Electrical COC ...) for every active supplier that has no ticked types at all. Additive and idempotent: nothing is changed or removed, `specialty` is untouched,
 * a supplier that already has any ticked type is left exactly as it is, and an agency without a matching active type gets nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        $types = DB::table('agency_service_types')->where('is_active', true)->whereNull('deleted_at')->get(['agency_id', 'code', 'label']);
        $now = now();

        foreach ($types->groupBy('agency_id') as $agencyId => $agencyTypes) {
            $byLegacy = [];
            foreach ($agencyTypes as $t) {
                $legacy = AgencyServiceProvider::legacySpecialtyForType($t->code, $t->label);
                if ($legacy !== 'other' && ! isset($byLegacy[$legacy])) {
                    $byLegacy[$legacy] = $t->code;   // the first (by id) type of that trade
                }
            }
            if ($byLegacy === []) {
                continue;
            }

            $providers = DB::table('agency_service_providers')
                ->where('agency_id', $agencyId)->whereNull('deleted_at')->whereIn('specialty', array_keys($byLegacy))->get(['id', 'specialty']);
            foreach ($providers as $p) {
                $has = DB::table('agency_service_provider_service_types')->where('service_provider_id', $p->id)->whereNull('deleted_at')->exists();
                if ($has) {
                    continue;
                }
                DB::table('agency_service_provider_service_types')->insert([
                    'agency_id' => $agencyId, 'service_provider_id' => $p->id, 'service_type' => $byLegacy[$p->specialty], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // data backfill: nothing to undo (the ticks are ordinary supplier trades)
    }
};
