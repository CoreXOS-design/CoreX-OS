<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-26 — the three-part clause chain: "late estate of piet
 * (id) herein represented by exec pty ltd (reg) represented by Koos
 * (id)." The deceased and the signing person both already resolve from
 * signer_name/signer_id_number; the COMPANY in the middle has nowhere to
 * live. Frozen at generation time (same "resolve once, snapshot"
 * contract as party_clause_text/signer_id_number, not a live join) so a
 * clause a document was actually sent with never reprints differently
 * later if the supplier directory record changes.
 *
 * Only ever set on a recipient row that is ITSELF a supplier's working
 * contact standing in as another party's representative
 * (RecipientTemplate::resolveSlotDisplayName()'s type:'recipient'
 * branch) — an ordinary recipient's row stays null, same as
 * party_clause_text does for a party with no chain at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('supplier_firm_name', 255)->nullable()->after('party_clause_text');
            $table->string('supplier_firm_registration_number', 100)->nullable()->after('supplier_firm_name');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn(['supplier_firm_name', 'supplier_firm_registration_number']);
        });
    }
};
