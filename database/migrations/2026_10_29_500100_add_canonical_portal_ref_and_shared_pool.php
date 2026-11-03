<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Property identity A2 (.ai/specs/property-identity.md §3, §4).
 *
 *  - canonical_ref ("p24:116123456" / "pp:T1014486") beside every raw advert number, nullable and indexed.
 *    The raw columns are untouched; `property-refs:normalise` fills the new ones.
 *  - tracked_property_external_refs also gets the confidence/method the link was decided with (written by the
 *    resolver in A3; NULL = unknown for rows made before).
 *  - portal_advert_facts: the global shared pool — PUBLIC advert facts only, keyed by canonical_ref. No agency_id
 *    on purpose (spec §3): the pool never records which agency contributed.
 *
 * Every new column is nullable. Nothing existing is changed or dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_property_external_refs', function (Blueprint $t) {
            $t->string('canonical_ref', 40)->nullable()->after('source_ref');
            $t->string('match_confidence', 10)->nullable()->after('canonical_ref');
            $t->string('match_method', 30)->nullable()->after('match_confidence');
            $t->timestamp('matched_at')->nullable()->after('match_method');
            // Not unique yet: the same advert sits on two property files in 4,561 places today. The unique key
            // (agency_id, canonical_ref) goes on once the reconcile report has been reviewed and applied (spec §4).
            $t->index(['agency_id', 'canonical_ref'], 'idx_tper_agency_canonical');
            $t->index('canonical_ref', 'idx_tper_canonical');
        });

        Schema::table('prospecting_listings', function (Blueprint $t) {
            $t->string('canonical_ref', 40)->nullable()->after('portal_ref');
            $t->index(['agency_id', 'canonical_ref'], 'idx_pl_agency_canonical');
        });

        Schema::table('p24_listings', function (Blueprint $t) {
            $t->string('canonical_ref', 40)->nullable()->after('p24_listing_number');
            $t->index('canonical_ref', 'idx_p24l_canonical');
        });

        Schema::table('portal_listings', function (Blueprint $t) {
            $t->string('canonical_ref', 40)->nullable()->after('portal_listing_id');
            $t->index('canonical_ref', 'idx_portall_canonical');
        });

        Schema::table('property_external_sources', function (Blueprint $t) {
            $t->string('canonical_ref', 40)->nullable()->after('listing_ref');
            $t->index(['agency_id', 'canonical_ref'], 'idx_pes_agency_canonical');
        });

        Schema::table('properties', function (Blueprint $t) {
            $t->string('p24_canonical_ref', 40)->nullable();
            $t->string('pp_canonical_ref', 40)->nullable();
            $t->index(['agency_id', 'p24_canonical_ref'], 'idx_prop_agency_p24canon');
            $t->index(['agency_id', 'pp_canonical_ref'], 'idx_prop_agency_ppcanon');
        });

        Schema::create('portal_advert_facts', function (Blueprint $t) {
            $t->id();
            $t->string('canonical_ref', 40)->unique();
            $t->string('portal', 8);
            $t->string('listing_id', 30);
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
            $t->unsignedBigInteger('price')->nullable();
            $t->unsignedTinyInteger('bedrooms')->nullable();
            $t->decimal('bathrooms', 4, 1)->nullable();
            $t->unsignedTinyInteger('garages')->nullable();
            $t->unsignedInteger('floor_size_m2')->nullable();
            $t->unsignedInteger('erf_size_m2')->nullable();
            $t->string('property_type', 60)->nullable();
            $t->string('listing_agent_name', 191)->nullable();
            $t->string('listing_agency_name', 191)->nullable();
            $t->string('status', 20)->default('unknown'); // active | sold | withdrawn | unknown
            $t->timestamp('first_seen_at')->nullable();
            $t->timestamp('last_seen_at')->nullable();
            $t->json('price_history')->nullable();
            $t->unsignedInteger('seen_count')->default(0);
            $t->timestamps();

            $t->index(['suburb_normalised', 'street_name'], 'idx_paf_suburb_street');
            $t->index(['latitude', 'longitude'], 'idx_paf_gps');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_advert_facts');

        Schema::table('properties', function (Blueprint $t) {
            $t->dropIndex('idx_prop_agency_p24canon');
            $t->dropIndex('idx_prop_agency_ppcanon');
            $t->dropColumn(['p24_canonical_ref', 'pp_canonical_ref']);
        });
        Schema::table('property_external_sources', function (Blueprint $t) {
            $t->dropIndex('idx_pes_agency_canonical');
            $t->dropColumn('canonical_ref');
        });
        Schema::table('portal_listings', function (Blueprint $t) {
            $t->dropIndex('idx_portall_canonical');
            $t->dropColumn('canonical_ref');
        });
        Schema::table('p24_listings', function (Blueprint $t) {
            $t->dropIndex('idx_p24l_canonical');
            $t->dropColumn('canonical_ref');
        });
        Schema::table('prospecting_listings', function (Blueprint $t) {
            $t->dropIndex('idx_pl_agency_canonical');
            $t->dropColumn('canonical_ref');
        });
        Schema::table('tracked_property_external_refs', function (Blueprint $t) {
            $t->dropIndex('idx_tper_agency_canonical');
            $t->dropIndex('idx_tper_canonical');
            $t->dropColumn(['canonical_ref', 'match_confidence', 'match_method', 'matched_at']);
        });
    }
};
