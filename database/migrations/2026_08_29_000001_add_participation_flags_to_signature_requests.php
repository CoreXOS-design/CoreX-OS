<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elize's rule via Johan, 2026-08-24: every party on a document always
 * displays with full details (name/surname/ID/address/tel/email); everyone
 * signs UNLESS deceased (never signs — their representative signs in their
 * place) or collapsed by a proxy flag held by someone else in their group
 * (only the proxy signs; everyone else still displays).
 *
 * Both flags are PER-RECIPIENT, PER-DOCUMENT — set on the recipient screen
 * for this document, not a permanent attribute of the Contact. (Johan
 * explicitly corrected an earlier steer that proxy might live at the
 * contact/representative level: "the authoritative flag is per-recipient,
 * per-document... someone may hold proxy for this deal and not the next
 * one." contact_representatives.signs_as_proxy — the entity-rep foundation's
 * own column — may still seed a sensible DEFAULT for is_proxy when a
 * recipient is created from a linked representative, but it is never
 * authoritative once set here.)
 *
 * These two columns are what SignatureRequest::isSigningParticipant() /
 * nonSigningReason() read — the single predicate the notification dispatch
 * choke point (SignatureService::sendSigningRequest()) checks before ever
 * sending an invitation. Additive, nullable-safe defaults: every existing
 * row gets is_deceased=false, is_proxy=false, i.e. exactly today's
 * behaviour (everyone signs) — zero change for any document that predates
 * this feature.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->boolean('is_deceased')->default(false)->after('party_clause_text');
            $table->boolean('is_proxy')->default(false)->after('is_deceased');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn(['is_deceased', 'is_proxy']);
        });
    }
};
