<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §13 — Johan's approved capture-screen
 * mockup adds a per-line CONDITION at move-in ("Condition chips per line
 * from the agency-configurable vocabulary"), a distinct concept from §8's
 * move-out `disposition_presets` (which grades the DELTA between move-in
 * and move-out, not the item's own state when captured). Same
 * agency-configurable {key, label, requires_notes} shape as both
 * `disposition_presets` on this table and `condition_states` on
 * RentalInspectionSetting — a third instance of the same pattern, on its
 * own column, for the same reason §8's own migration already gives for not
 * folding this settings model into RentalInspectionSetting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inventory_settings', function (Blueprint $table) {
            $table->json('condition_states')->nullable()->after('disposition_presets');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inventory_settings', function (Blueprint $table) {
            $table->dropColumn('condition_states');
        });
    }
};
