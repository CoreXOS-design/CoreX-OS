<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-24 — "the part I care most about." The resolved
 * representative-wording sentence (e.g. "The late estate of Estate Late John
 * Smith (Estate No: 1234/2026), herein represented by Jane Smith in the
 * capacity of Executor") is computed ONCE, when the signing request is
 * created (SignatureService::createSigningRequest(), mirrors how
 * signer_caption already works), and stored here. The document-rendering
 * path (RoleBlockExpansionService::resolveContactValue) reads THIS column
 * for an entity party whenever a SignatureRequest already exists for that
 * role — never re-resolves live from representative_wording_templates for an
 * already-generated request. An agency editing a wording template later must
 * never change what an already-sent-or-signed document says: same failure
 * class as the unconditional-completion-overwrite bug found the same
 * morning this was specced, except silent and permanent if left undesigned.
 *
 * Additive + nullable: only entity-representative signers carry this; every
 * existing/ordinary signer stays NULL. Live/pre-send preview rendering
 * (no SignatureRequest yet) still resolves live — nothing has been
 * "generated" yet at that point, so there is nothing to freeze.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->text('party_clause_text')->nullable()->after('signer_caption');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn('party_clause_text');
        });
    }
};
