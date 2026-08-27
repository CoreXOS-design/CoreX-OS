<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * cc4's finding, cc2 2026-08-26 — "a revoked representative can still
 * sign." Every guard built tonight ran at creation time; nothing re-checked
 * the relationship at the moment of signing, because nothing persisted WHO
 * a signer was standing in for — createSigningRequest()'s
 * $representedContactId was only ever a transient argument used for a
 * one-time check, then discarded.
 *
 * represented_contact_id: the party this row's signer (contact_id) is
 * claiming to represent, when they are one — null for the overwhelming
 * majority of rows (an ordinary party signing for themselves). Persisted
 * so SignatureRequest::isSigningBlocked() can re-verify the relationship
 * still holds every time the signing link is opened or acted on, not only
 * once at generation — using the SAME Contact::signingRepresentatives()
 * resolution the create-time guard already uses, never a second check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->unsignedBigInteger('represented_contact_id')->nullable()->after('contact_id');
            $table->foreign('represented_contact_id')->references('id')->on('contacts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropForeign(['represented_contact_id']);
            $table->dropColumn('represented_contact_id');
        });
    }
};
