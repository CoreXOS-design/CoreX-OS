<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('tracked_properties', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();

            // ─── Identity ─────────────────────────────
            $table->uuid('external_id')->unique();

            // ─── Address (structured) ─────────────────
            $table->string('street_number', 50)->nullable();
            $table->string('street_name', 200)->nullable();
            $table->string('unit_number', 50)->nullable();
            $table->string('complex_name', 200)->nullable();
            $table->string('suburb', 100)->nullable();
            // suburb_normalised: lowercased + trimmed + punctuation-stripped.
            // Set by the model boot() hook on every save when suburb changes.
            $table->string('suburb_normalised', 100)->nullable();
            $table->string('town', 100)->nullable();
            $table->string('province', 100)->nullable();
            $table->string('postal_code', 20)->nullable();

            // ─── Geo ──────────────────────────────────
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            // cma_gps_* is the deeds-office authoritative GPS from CMAInfo OCR.
            // Kept separate from portal-derived lat/long so neither overwrites the other.
            $table->decimal('cma_gps_lat', 10, 7)->nullable();
            $table->decimal('cma_gps_lng', 10, 7)->nullable();

            // ─── Deeds-office identity ────────────────
            $table->string('erf_number', 100)->nullable();
            $table->string('title_deed_number', 100)->nullable();
            // cadastral_extent stored as string to preserve "1 116 m²" formatting variants.
            $table->string('cadastral_extent', 50)->nullable();

            // ─── Valuation snapshot ───────────────────
            $table->decimal('municipal_valuation', 15, 2)->nullable();
            $table->unsignedSmallInteger('municipal_valuation_year')->nullable();
            $table->decimal('last_known_asking_price', 15, 2)->nullable();
            $table->decimal('last_known_sold_price', 15, 2)->nullable();
            $table->date('last_known_sold_date')->nullable();

            // ─── Property attributes ──────────────────
            $table->string('property_type', 50)->nullable();
            $table->unsignedTinyInteger('bedrooms')->nullable();
            $table->unsignedTinyInteger('bathrooms')->nullable();
            $table->unsignedTinyInteger('garages')->nullable();
            $table->decimal('floor_size_m2', 10, 2)->nullable();
            $table->decimal('erf_size_m2', 10, 2)->nullable();

            // ─── Promotion linkage ────────────────────
            // Set when a Tracked Property is promoted to Agency Stock (mandate signed).
            $table->foreignId('promoted_to_property_id')
                  ->nullable()
                  ->constrained('properties')
                  ->nullOnDelete();
            $table->timestamp('promoted_at')->nullable();
            $table->foreignId('promoted_by_user_id')
                  ->nullable()
                  ->constrained('users')
                  ->nullOnDelete();

            // ─── Source attribution ───────────────────
            // source_chain: append-only audit log of every contribution.
            // Shape: [{type, ref, date, fields_contributed: [...]}]
            $table->json('source_chain')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_enriched_at')->nullable();
            $table->string('last_enrichment_source', 100)->nullable();

            // ─── Status ───────────────────────────────
            // active   = live tracked property
            // archived = manually withdrawn
            // duplicate= flagged as a duplicate of another tracked property
            // promoted = upgraded to Agency Stock (read-only after this point)
            $table->enum('status', ['active', 'archived', 'duplicate', 'promoted'])->default('active');
            $table->unsignedBigInteger('duplicate_of_tracked_property_id')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'suburb_normalised'], 'idx_tracked_props_agency_suburb');
            $table->index(['agency_id', 'erf_number'], 'idx_tracked_props_agency_erf');
            $table->index(['agency_id', 'status'], 'idx_tracked_props_agency_status');
            $table->index(['promoted_to_property_id'], 'idx_tracked_props_promoted');
            $table->index(['latitude', 'longitude'], 'idx_tracked_props_geo');
            $table->index(['cma_gps_lat', 'cma_gps_lng'], 'idx_tracked_props_cma_geo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracked_properties');
    }
};
