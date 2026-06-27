<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-105 enhancement — per-doc-type CONTACT ROLE routing (MANY) + FICA SLOT.
 *
 * Two new catalogue attributes on the global `document_types` table, mirroring
 * how `grouping` already drives default Save-To:
 *
 *  - `contact_roles` (JSON) — the SET of parties a split page of this type may
 *    route to. A page can be assigned to ONE OR MANY contacts ACROSS these
 *    roles (the OTP proves it: one page → all sellers AND all buyers). Allowed
 *    role tokens: seller_owner | buyer | tenant | landlord | lessor.
 *    'seller_owner' resolves across the SET [seller, owner] (esign writes
 *    'owner' for sellers — investigation §3). [] / null = routes to no contact.
 *  - `fica_slot` (string) — which wet-ink FICA upload slot a FICA-relevant page
 *    fills: id | por | fica_form | none. (Single — a page is one slot.)
 *
 * These REPLACE the two former hardcodes in PdfSplitterController: the
 * slug→FICA-slot map and the party_role default.
 *
 * The per-agency OVERRIDE lives on `agency_document_type_compliance` as NULLABLE
 * columns — NULL means "inherit the catalogue default", the AT-105 Save-To
 * pattern, so existing rows need zero backfill. Additive only — no data loss.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Catalogue defaults on the global document_types table.
        Schema::table('document_types', function (Blueprint $table) {
            $table->json('contact_roles')->nullable()->after('grouping');
            $table->string('fica_slot', 20)->default('none')->after('contact_roles');
        });

        // 2. Seed sensible catalogue role-sets for the known seeded types.
        //    Seller-side documents → [seller_owner]; the OTP → BOTH sides; pure
        //    property documents stay null (route to no contact by default).
        foreach (['mandate', 'fica', 'ids', 'por', 'disclosure', 'listing_form'] as $slug) {
            DB::table('document_types')->where('slug', $slug)->update(['contact_roles' => json_encode(['seller_owner'])]);
        }
        DB::table('document_types')->where('slug', 'offer_to_purchase')->update(['contact_roles' => json_encode(['seller_owner', 'buyer'])]);

        // 3. Seed the FICA slot for the three FICA-relevant types.
        DB::table('document_types')->where('slug', 'fica')->update(['fica_slot' => 'fica_form']);
        DB::table('document_types')->where('slug', 'ids')->update(['fica_slot' => 'id']);
        DB::table('document_types')->where('slug', 'por')->update(['fica_slot' => 'por']);

        // 4. Per-agency override columns — NULL = inherit the catalogue default.
        Schema::table('agency_document_type_compliance', function (Blueprint $table) {
            $table->json('contact_roles')->nullable()->after('save_to_contact');
            $table->string('fica_slot', 20)->nullable()->after('contact_roles');
        });
    }

    public function down(): void
    {
        Schema::table('document_types', function (Blueprint $table) {
            $table->dropColumn(['contact_roles', 'fica_slot']);
        });
        Schema::table('agency_document_type_compliance', function (Blueprint $table) {
            $table->dropColumn(['contact_roles', 'fica_slot']);
        });
    }
};
