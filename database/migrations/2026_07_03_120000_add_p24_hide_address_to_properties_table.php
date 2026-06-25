<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-P24 — Property24 "hide address" control.
 *
 * Adds `properties.p24_hide_address` — drives P24 ONLY (Property24ListingMapper
 * sets showLocation=false when this is 1). This is INDEPENDENT of the existing
 * PP `pp_hide_*` flags: an agency may hide the address on P24 while showing it
 * on Private Property, or vice-versa. The two controls are never fused.
 *
 * Defaults (Johan's "go live private — opt-IN to show" decision):
 *   - p24_hide_address       → DEFAULT 1 (new/future stock hidden on P24)
 *   - pp_hide_street_name    → DEFAULT 1 (new/future stock hidden on PP)
 *   - pp_hide_street_number  → DEFAULT 1
 *   - pp_hide_complex_name   → DEFAULT 1
 *   - pp_hide_unit_number    → DEFAULT 1
 *
 * EXISTING stock is left UNCHANGED here — the new column's default-backfill is
 * reset to 0 so current listings keep showing the address until the separate,
 * Johan-confirmed bulk-set step runs. The PP default changes use ALTER COLUMN
 * SET DEFAULT, which only governs FUTURE inserts — existing rows and the
 * existing toggle wiring are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        // New column. default(true) means future inserts start HIDDEN; it also
        // backfills existing rows to 1 — which we immediately reset below so we
        // do NOT pre-empt the bulk-set decision.
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('p24_hide_address')->default(true)->after('pp_hide_unit_number');
        });

        // Preserve CURRENT behaviour for existing stock (shown until bulk-set).
        DB::table('properties')->update(['p24_hide_address' => false]);

        // PP new-stock default → HIDDEN (future inserts only). ALTER COLUMN
        // SET DEFAULT changes the default without rewriting existing values or
        // touching nullability/toggle wiring.
        foreach ([
            'pp_hide_street_name',
            'pp_hide_street_number',
            'pp_hide_complex_name',
            'pp_hide_unit_number',
        ] as $col) {
            DB::statement("ALTER TABLE properties ALTER COLUMN {$col} SET DEFAULT 1");
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('p24_hide_address');
        });

        // Restore the original PP defaults (shown).
        foreach ([
            'pp_hide_street_name',
            'pp_hide_street_number',
            'pp_hide_complex_name',
            'pp_hide_unit_number',
        ] as $col) {
            DB::statement("ALTER TABLE properties ALTER COLUMN {$col} SET DEFAULT 0");
        }
    }
};
