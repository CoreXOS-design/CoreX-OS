<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-XX Viewing Pack — Step 1: document-type BUYER-PACK eligibility.
 *
 * Adds the same two-layer flag pattern AT-105 established for Save-To / contact
 * routing (see .ai/audits/2026-06-28-viewing-pack-doctype-audit.md, verdict A):
 *
 *  - Catalogue DEFAULT on the global `document_types` table — a non-null boolean
 *    `buyer_pack_eligible`. This is THE single canonical definition of which
 *    document types may be surfaced in a buyer's Viewing Pack.
 *  - Per-agency OVERRIDE on `agency_document_type_compliance` — a NULLABLE
 *    boolean. NULL = "inherit the catalogue default"; an explicit true/false is
 *    the agency's own choice. Additive only; existing rows need zero backfill.
 *
 * Resolution (override-over-default) lives in AgencyComplianceDocTypeService,
 * mirroring resolveDestination()/resolveRouting(). No eligibility is ever
 * hardcoded in app code — the ONLY place a default is seeded is right here.
 */
return new class extends Migration
{
    /**
     * Catalogue defaults — buyer-relevant documents a viewing buyer legitimately
     * wants to see. Identity/compliance/mandate/transaction types stay false
     * (the column default), so seller IDs, FICA, mandates and OTPs are never
     * buyer-eligible unless an agency deliberately opts a type in. Slugs are
     * matched against the live catalogue; any slug absent from this install is
     * simply skipped (zero rows updated — safe).
     */
    private const ELIGIBLE_BY_DEFAULT = [
        'rates_taxes',       // municipal rates statement — buyer cost to carry
        'body_corporate',    // body-corporate / levy statement — buyer cost to carry
        'house_rules',       // sectional-scheme conduct rules the buyer must know
        'disclosure',        // mandatory seller disclosure (PPA s67) — buyer-facing by law
        'condition_report',  // property condition — buyer-relevant
        'inspection_report', // inspection findings — buyer-relevant
    ];

    public function up(): void
    {
        // 1. Catalogue default on the global document_types catalogue.
        Schema::table('document_types', function (Blueprint $table) {
            $table->boolean('buyer_pack_eligible')->default(false)->after('fica_slot');
        });

        // 2. Seed sensible catalogue defaults (true) for buyer-relevant types.
        //    Everything else keeps the column default (false).
        DB::table('document_types')
            ->whereIn('slug', self::ELIGIBLE_BY_DEFAULT)
            ->update(['buyer_pack_eligible' => true]);

        // 3. Per-agency override column — NULL = inherit the catalogue default.
        Schema::table('agency_document_type_compliance', function (Blueprint $table) {
            $table->boolean('buyer_pack_eligible')->nullable()->after('fica_slot');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn('buyer_pack_eligible');
        });
        Schema::table('agency_document_type_compliance', function (Blueprint $table) {
            $table->dropColumn('buyer_pack_eligible');
        });
    }
};
