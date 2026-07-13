<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-238 — NEW filing entries point at the real records instead of retyping them.
 *
 * A filing row used to retype facts CoreX already held: the property as a free-text
 * address, the seller as a free-text name, the expiry by hand. Retyped facts drift.
 * New entries now carry the links.
 *
 * DELIBERATELY NOT BACKFILLED (Johan, 2026-07-13). The 2,069 historical rows stay exactly
 * as they are — free text, untouched, forever, still viewable and editable as they always
 * were. Matching them by address was investigated and rejected: a lone address match is not
 * a correct one ("32 Queen View" matches only 29 Queens View; "10 Wingate Avenue" matches
 * only 7 Wingate Avenue), and a confidently wrong link on a legal filing record is worse
 * than the free text it replaced. So there is no link provenance and no review queue: a
 * link exists because a human picked it in the form, or it does not exist at all.
 *
 * The free-text columns therefore STAY, and stay authoritative wherever there is no link —
 * for every historical row, and for new edge-case entries whose property CoreX does not
 * hold. Linking is an upgrade, never a gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_filing_register', function (Blueprint $table) {
            if (! Schema::hasColumn('document_filing_register', 'property_id')) {
                $table->foreignId('property_id')->nullable()
                    ->after('agent_id')
                    ->constrained('properties', 'id', 'dfr_property_fk')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('document_filing_register', 'seller_contact_id')) {
                $table->foreignId('seller_contact_id')->nullable()
                    ->after('property_id')
                    ->constrained('contacts', 'id', 'dfr_seller_contact_fk')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        // Foreign keys FIRST: MySQL refuses to drop an index a constraint still needs.
        Schema::table('document_filing_register', function (Blueprint $table) {
            $table->dropForeign('dfr_property_fk');
            $table->dropForeign('dfr_seller_contact_fk');
        });

        Schema::table('document_filing_register', function (Blueprint $table) {
            $table->dropColumn(['property_id', 'seller_contact_id']);
        });
    }
};
