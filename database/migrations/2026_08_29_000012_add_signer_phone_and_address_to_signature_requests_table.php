<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-08-28 — an agent's search for a seller can fail to find a
 * real contact that isn't perfectly tagged in the CRM (a separate, open
 * search-filter question). Blocked there, the agent's only path is the
 * recipient card's own always-editable phone/address fields — they type
 * the values straight in, see them on screen, and believe they are
 * captured. Without these columns, a recipient with no linked Contact
 * had nowhere for phone/address to live: id_number/email/name already
 * had signer_id_number/signer_email/signer_name to fall back to when no
 * Contact exists (c38c50b7e), but phone and address silently vanished
 * from the generated document — the same "screen shows one thing, the
 * document shows another" class of fault as the empty-field
 * concatenation bug, this time a silent full omission rather than a
 * wrong value, on a legal document, with no warning to the agent that
 * it happened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->string('signer_phone', 50)->nullable()->after('signer_id_number');
            $table->string('signer_address', 500)->nullable()->after('signer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('signature_requests', function (Blueprint $table) {
            $table->dropColumn(['signer_phone', 'signer_address']);
        });
    }
};
