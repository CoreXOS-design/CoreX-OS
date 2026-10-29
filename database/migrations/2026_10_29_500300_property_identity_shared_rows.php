<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property identity A5 (.ai/specs/property-identity.md §14): the shared rows across agencies. PUBLIC facts only.
 *
 *  - shared_properties: one row per physical property across agencies (public columns only — no erf, deed, SG code,
 *    owner, claim, status). Has NO agency_id: it is shared.
 *  - portal_advert_facts.shared_property_id: which physical property an advert belongs to (relists, both portals).
 *  - portal_advert_contributions: INTERNAL record of which agency contributed which advert and which public fields.
 *    Agency-scoped by its model; never returned by the pool, never shown to another agency.
 *  - tracked_properties.shared_property_id / prospecting_listings.portal_advert_fact_id: each agency's own row points at
 *    the shared row. Both stay NULL until the share step is applied (it has no --apply yet; Johan reviews first).
 *
 * Every column is nullable. Nothing existing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shared_properties', function (Blueprint $t) {
            $t->id();
            $t->string('address_text', 500)->nullable();
            $t->string('street_number', 20)->nullable();
            $t->string('street_name', 191)->nullable();
            $t->string('unit_number', 30)->nullable();
            $t->string('complex_name', 191)->nullable();
            $t->string('suburb', 120)->nullable();
            $t->string('suburb_normalised', 120)->nullable();
            $t->string('town', 120)->nullable();
            $t->string('province', 60)->nullable();
            $t->decimal('latitude', 10, 7)->nullable();
            $t->decimal('longitude', 10, 7)->nullable();
            $t->string('geo_confidence', 20)->nullable();
            $t->string('property_type', 60)->nullable();
            $t->unsignedTinyInteger('bedrooms')->nullable();
            $t->decimal('bathrooms', 4, 1)->nullable();
            $t->unsignedTinyInteger('garages')->nullable();
            $t->unsignedInteger('floor_size_m2')->nullable();
            $t->unsignedInteger('erf_size_m2')->nullable();
            $t->timestamps();
            $t->index(['suburb_normalised', 'street_name'], 'idx_sp_suburb_street');
        });

        Schema::table('portal_advert_facts', function (Blueprint $t) {
            $t->unsignedBigInteger('shared_property_id')->nullable();
            $t->index('shared_property_id', 'idx_paf_shared_property');
        });

        Schema::create('portal_advert_contributions', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('portal_advert_fact_id');
            $t->unsignedBigInteger('agency_id');
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->unsignedInteger('seen_count')->default(0);
            $t->json('fields_contributed')->nullable();
            $t->timestamps();
            $t->unique(['portal_advert_fact_id', 'agency_id'], 'unq_pac_fact_agency');
            $t->index('agency_id', 'idx_pac_agency');
            $t->foreign('portal_advert_fact_id', 'fk_pac_fact')->references('id')->on('portal_advert_facts')->cascadeOnDelete();
            $t->foreign('agency_id', 'fk_pac_agency')->references('id')->on('agencies')->cascadeOnDelete();
        });

        Schema::table('tracked_properties', function (Blueprint $t) {
            $t->unsignedBigInteger('shared_property_id')->nullable();
            $t->index('shared_property_id', 'idx_tp_shared_property');
        });

        Schema::table('prospecting_listings', function (Blueprint $t) {
            $t->unsignedBigInteger('portal_advert_fact_id')->nullable();
            $t->index('portal_advert_fact_id', 'idx_pl_advert_fact');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $t) {
            $t->dropIndex('idx_pl_advert_fact');
            $t->dropColumn('portal_advert_fact_id');
        });
        Schema::table('tracked_properties', function (Blueprint $t) {
            $t->dropIndex('idx_tp_shared_property');
            $t->dropColumn('shared_property_id');
        });
        Schema::dropIfExists('portal_advert_contributions');
        Schema::table('portal_advert_facts', function (Blueprint $t) {
            $t->dropIndex('idx_paf_shared_property');
            $t->dropColumn('shared_property_id');
        });
        Schema::dropIfExists('shared_properties');
    }
};
