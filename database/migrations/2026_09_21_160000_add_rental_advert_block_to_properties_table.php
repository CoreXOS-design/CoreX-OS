<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-property-tab.md §4.0/§4.1/§7, Part 5. `rental_advert_block_enabled`
 * is the property-level master opt-in — off on every property, including
 * every existing one, so no existing hand-typed advert is ever touched
 * without an agent explicitly turning this on (§4.0/§4.5). `advertise_core_fields`
 * is a JSON array of core-field keys (currently only 'admin_fee' and
 * 'marketing_fee' are eligible — see RentalAdvertBlockService::CORE_FIELDS)
 * ticked to appear in the generated block; one JSON column rather than a
 * column per core field, matching the same pattern
 * PropertyRentalDetailsCustomField.advertise and
 * properties.rental_details_custom_field_values already establish for this
 * exact feature (agreed with cc3 before building).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->boolean('rental_advert_block_enabled')->default(false)->after('rental_details_custom_field_values');
            $table->json('advertise_core_fields')->nullable()->after('rental_advert_block_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['rental_advert_block_enabled', 'advertise_core_fields']);
        });
    }
};
