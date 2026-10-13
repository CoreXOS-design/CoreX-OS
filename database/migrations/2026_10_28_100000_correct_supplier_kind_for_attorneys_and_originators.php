<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Supplier kind correction (rentals-faults-work-orders.md section 20.9, Johan 13 Oct 2026). Every supplier was created as "Contractor", so transfer
 * attorneys and bond originators read as Contractors in the Supplier Directory. This sets the new kinds from the trade, ONCE, on suppliers that are still
 * plain Contractors (a kind somebody chose is never touched): Transfer / Bond attorney, Conveyancer or an attorney-flagged firm -> Attorney / conveyancer;
 * Bond originator -> Bond originator. Each change is written to the supplier's own change history as a system correction (no user). Additive, idempotent
 * (a second run finds nothing left to change). No data is deleted and the trade, flags and every other column are untouched.
 */
return new class extends Migration
{
    private const LABEL = ['attorney_conveyancer' => 'Attorney / conveyancer', 'bond_originator' => 'Bond originator'];

    public function up(): void
    {
        if (! Schema::hasTable('agency_service_providers') || ! Schema::hasColumn('agency_service_providers', 'kind')) {
            return;
        }
        $hasEvents = Schema::hasTable('agency_service_provider_events');
        $changed = ['attorney_conveyancer' => 0, 'bond_originator' => 0];

        $rows = DB::table('agency_service_providers')->where('kind', 'contractor')
            ->where(fn ($q) => $q->whereIn('specialty', ['transfer_attorney', 'bond_attorney', 'conveyancer', 'bond_originator'])
                ->orWhere('is_transfer_attorney', 1)->orWhere('is_bond_attorney', 1))
            ->get(['id', 'agency_id', 'specialty', 'is_transfer_attorney', 'is_bond_attorney']);

        foreach ($rows as $r) {
            $kind = $r->specialty === 'bond_originator' ? 'bond_originator' : 'attorney_conveyancer';
            $trade = ucwords(str_replace('_', ' ', (string) $r->specialty));
            DB::table('agency_service_providers')->where('id', $r->id)->where('kind', 'contractor')->update(['kind' => $kind]);
            if ($hasEvents) {
                DB::table('agency_service_provider_events')->insert([
                    'agency_id' => $r->agency_id, 'service_provider_id' => $r->id, 'event' => 'kind_corrected',
                    'note' => 'Kind corrected from Contractor to ' . self::LABEL[$kind] . ' by the system (the trade is ' . $trade . ').',
                    'user_id' => null, 'created_at' => now(),
                ]);
            }
            $changed[$kind]++;
        }
        Log::info('supplier kind correction', $changed);
    }

    public function down(): void
    {
        // Deliberately a no-op: the old kind (everything "Contractor") was the wrong data; the history rows say what changed.
    }
};
