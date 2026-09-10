<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-402 Part 4 — two of the three new rental fields Johan approved. Additive
 * only: three new nullable/defaulted columns on the existing `properties`
 * table, nothing renamed, nothing dropped. Fully reversible.
 *
 * The third approved field ("move-in Availability date") is NOT a new column
 * here — `properties.occupation_date` already exists (added long before this
 * work, populated by the P24 CSV importer, read by document generation) and
 * is exactly this concept; it has simply never had a screen to enter it on
 * manually. Adding a second, differently-named column for the same idea
 * would be the wrong call. See .ai/specs/rentals-shared-screens.md §11 for
 * the full reasoning.
 *
 * `furnished_status` stores the matching PropertySettingItem.name text
 * (group 'furnished_status'), the same convention `property_type`/`category`
 * already use — not a foreign key, not a hardcoded enum.
 *
 * `water_included`/`electricity_included`/`levies_included` are itemised,
 * not a single "utilities included" boolean — see the design reasoning
 * delivered alongside this migration (Private Property's own API already
 * has separate WaterIncluded/ElectricityIncluded attributes with dead
 * plumbing already coded on the CoreX side; a single boolean can't express
 * the ordinary case of "water's included, electricity isn't").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('furnished_status', 100)->nullable()->after('rental_price_type');
            $table->boolean('water_included')->default(false)->after('furnished_status');
            $table->boolean('electricity_included')->default(false)->after('water_included');
            $table->boolean('levies_included')->default(false)->after('electricity_included');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['furnished_status', 'water_included', 'electricity_included', 'levies_included']);
        });
    }
};
