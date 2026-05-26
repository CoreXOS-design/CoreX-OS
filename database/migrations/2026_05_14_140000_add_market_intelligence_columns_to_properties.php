<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // CMA-extracted property identity.
            // erf_number is the canonical erf/stand reference (free text, e.g. "442", "Erf 2071", "180").
            // property_number / stand_number remain as legacy free-text proxies from earlier ingestion paths.
            $table->string('erf_number', 100)->nullable()->after('stand_number');
            $table->string('title_deed_number', 100)->nullable()->after('erf_number');

            // CMA-extracted valuation (Municipal value from CMAInfo PDF subject-property block).
            $table->decimal('municipal_valuation', 15, 2)->nullable()->after('rates_taxes');
            $table->unsignedSmallInteger('municipal_valuation_year')->nullable()->after('municipal_valuation');

            // CMA-extracted GPS — separate from existing latitude/longitude which is P24-CSV-only per
            // the market-intelligence discovery audit. CMA coordinates are deeds-office authoritative
            // when present, but we do NOT overwrite P24 CSV data. Map consumers can prefer cma_gps_*
            // and fall back to latitude/longitude when null.
            $table->decimal('cma_gps_lat', 10, 7)->nullable()->after('longitude');
            $table->decimal('cma_gps_lng', 10, 7)->nullable()->after('cma_gps_lat');

            // Traceability for stale-protection in PropertyCmaPropagationService.
            // last_cma_at = source presentation's updated_at when the propagation last ran.
            // last_cma_presentation_id = soft pointer (no FK; presentations.agency_id is enforced via
            // service-layer agency check, and presentation soft-deletion would not auto-null the pointer).
            $table->timestamp('last_cma_at')->nullable()->after('cma_gps_lng');
            $table->unsignedBigInteger('last_cma_presentation_id')->nullable()->after('last_cma_at');

            $table->index('last_cma_at', 'idx_properties_last_cma_at');
            $table->index('erf_number', 'idx_properties_erf_number');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('idx_properties_last_cma_at');
            $table->dropIndex('idx_properties_erf_number');
            $table->dropColumn([
                'erf_number',
                'title_deed_number',
                'municipal_valuation',
                'municipal_valuation_year',
                'cma_gps_lat',
                'cma_gps_lng',
                'last_cma_at',
                'last_cma_presentation_id',
            ]);
        });
    }
};
