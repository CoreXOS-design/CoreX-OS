<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CMA / deeds ingestion (phase 1). Adds the deeds-specific fields the CMA Info
 * capture supplies that CoreX did not already have, plus the capture_kind
 * discriminator that keeps deeds captures on their own "Deeds Capture" screen
 * and OUT of the MIC Opportunities list. Reuses existing tracked_properties
 * columns for the rest (title_deed_number, erf_number, cadastral_extent =
 * section extent, last_known_sold_price/date = sale price/date, municipal
 * valuation, property_type, lat/lng, town = municipality, province,
 * owner_contact_id, source_chain, status).
 *
 * contacts.id_type flags whether id_number holds an SA ID or a company
 * registration — the owner ID is the JOIN KEY the phase-2 Virtual Agent uses
 * to fill the phone later, so both kinds live in one queryable column.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->string('capture_kind', 30)->nullable()->index();   // e.g. 'deeds_capture'
            $table->string('deeds_office', 100)->nullable();
            $table->string('scheme_name', 200)->nullable();
            $table->string('scheme_number', 100)->nullable();
            $table->string('section_number', 50)->nullable();
            $table->string('bond_holder', 150)->nullable();
            $table->decimal('bond_amount', 15, 2)->nullable();
            $table->string('sale_type', 60)->nullable();
            $table->date('deeds_registered_date')->nullable();
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->string('id_type', 20)->nullable();   // 'sa_id' | 'company_reg'
        });
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropIndex(['capture_kind']);
            $table->dropColumn([
                'capture_kind', 'deeds_office', 'scheme_name', 'scheme_number',
                'section_number', 'bond_holder', 'bond_amount', 'sale_type',
                'deeds_registered_date',
            ]);
        });

        Schema::table('contacts', function (Blueprint $table) {
            $table->dropColumn('id_type');
        });
    }
};
