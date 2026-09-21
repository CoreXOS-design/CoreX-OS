<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-property-tab.md §2/§7/§8, Part 2 — the VALUE side of
 * agency-defined rental-details fields (Part 1 was the DEFINITION side
 * only). Mirrors rental_applications.custom_field_values exactly: a single
 * nullable json column, keyed by the custom field's own `key`
 * (PropertyRentalDetailsCustomField), not a separate per-value table — same
 * reasoning as the sibling column, and the same reason a retired field's
 * key must never be reused by a different definition (see
 * PropertyRentalDetailsCustomField::generateKey()'s own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('rental_details_custom_field_values')->nullable()->after('levies_included');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_details_custom_field_values');
        });
    }
};
