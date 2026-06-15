<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-37 (Communication Capture Setup, Phase 1). The reveal audit log. Every time
 * a stored mailbox password is decrypted and shown — even by the principal who
 * owns the account — one row lands here. Append-only: "a credential was
 * retrieved and every retrieval is recorded" is audit-defensible; a silent
 * reveal is not. No soft-deletes (an audit trail is never edited or removed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mailbox_credential_reveals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'mbx_rvl_agency_fk')->cascadeOnDelete();
            $table->foreignId('mailbox_id')->constrained('communication_mailboxes', 'id', 'mbx_rvl_mailbox_fk')->cascadeOnDelete();

            // Who performed the reveal (the principal/owner who holds the perm).
            $table->foreignId('revealed_by')->constrained('users', 'id', 'mbx_rvl_by_fk')->cascadeOnDelete();
            // The user the mailbox belongs to (nullable — agency-keyed mailboxes
            // have no owning user).
            $table->foreignId('revealed_for_user_id')->nullable()->constrained('users', 'id', 'mbx_rvl_for_fk')->nullOnDelete();

            $table->timestamp('revealed_at');
            $table->string('ip_address', 45)->nullable();   // 45 = max IPv6 literal

            $table->timestamps();

            $table->index(['agency_id', 'mailbox_id'], 'mbx_rvl_agency_mailbox_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mailbox_credential_reveals');
    }
};
