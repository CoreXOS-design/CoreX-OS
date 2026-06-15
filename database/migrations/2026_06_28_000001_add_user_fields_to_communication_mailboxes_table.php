<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-37 (Communication Capture Setup, Phase 1). Gives the agency-only mailbox a
 * user dimension so a mailbox can be linked to the CoreX user whose address it
 * is, records which credential mechanism the row uses (IMAP now, OAuth later),
 * and who provisioned it (agency vs the user themselves — dual-control
 * provenance). All nullable / defaulted: existing rows stay agency-keyed,
 * untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            // Link the mailbox to the CoreX user whose address it is. Nullable:
            // OAuth domain-delegation (model 1) and pre-existing agency-list rows
            // may have no single owning user. nullOnDelete so archiving a user
            // never orphans/crashes the mailbox row.
            $table->foreignId('user_id')->nullable()->after('agency_id')
                ->constrained('users', 'id', 'comm_mbx_user_fk')->nullOnDelete();

            // Fetch mechanism for this mailbox. IMAP today; OAuth domain
            // connections (Phase 4) reuse the same archive with auth_type=oauth.
            $table->enum('auth_type', ['imap', 'oauth'])->default('imap')->after('encrypted_password');

            // Provenance: who set the credential. Drives dual-control (Phase 2).
            $table->enum('set_by', ['agency', 'user'])->nullable()->after('auth_type');

            $table->index(['agency_id', 'user_id'], 'comm_mbx_agency_user_idx');
        });
    }

    public function down(): void
    {
        Schema::table('communication_mailboxes', function (Blueprint $table) {
            $table->dropIndex('comm_mbx_agency_user_idx');
            $table->dropForeign('comm_mbx_user_fk');
            $table->dropColumn(['user_id', 'auth_type', 'set_by']);
        });
    }
};
