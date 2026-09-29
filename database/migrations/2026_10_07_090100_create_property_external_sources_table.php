<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/other-agency-stock.md §3 — source-of-record metadata for a
 * Property imported as ANOTHER agency's Property24/PrivateProperty listing
 * (status other_agency_stock). One row per Property, 1:1.
 *
 * A dedicated table rather than columns on `properties` (already 100+
 * columns, almost all null for the 99%+ of rows that are the agency's own
 * stock): mirrors the existing tracked_property_external_refs precedent
 * (migration 2026_05_14_170001) already used in this codebase for "which
 * portal + ref did this record come from" metadata, keeps the source data
 * cleanly separable/auditable, and never widens the properties table for a
 * feature most rows will never use.
 *
 * Dedup: unique (agency_id, portal, listing_ref). The import endpoint
 * updates the existing row on a re-import of the same listing rather than
 * creating a duplicate Property (spec §3).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('property_external_sources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->unique()->constrained()->cascadeOnDelete();

            $table->string('portal', 8); // 'p24' | 'pp'
            $table->string('listing_ref', 64);
            $table->string('listing_url', 2048)->nullable();

            $table->string('source_agency_name')->nullable();
            $table->string('source_agent_name')->nullable();
            $table->string('source_agent_phone', 64)->nullable();
            $table->string('source_agent_email')->nullable();
            $table->string('source_agent_profile_url', 2048)->nullable();

            $table->date('date_posted')->nullable();

            $table->timestamp('imported_at');
            $table->foreignId('imported_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'portal', 'listing_ref'], 'property_ext_src_agency_portal_ref_uq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_external_sources');
    }
};
