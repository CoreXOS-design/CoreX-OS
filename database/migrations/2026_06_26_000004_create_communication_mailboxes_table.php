<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Email adapter config (AT-32, spec §4.4). Agency-held IMAP credentials; the
 * password is stored encrypted. One queued poll job per mailbox (Phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'comm_mbx_agency_fk')->cascadeOnDelete();

            $table->string('email_address', 255);
            $table->string('imap_host', 255);
            $table->unsignedInteger('imap_port')->default(993);
            $table->string('username', 255);
            $table->text('encrypted_password')->nullable();
            $table->boolean('poll_inbox')->default(true);
            $table->boolean('poll_sent')->default(true);
            $table->unsignedInteger('poll_interval_minutes')->default(15);
            $table->timestamp('last_polled_at')->nullable();
            $table->unsignedBigInteger('last_uid_seen')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'active'], 'comm_mbx_agency_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_mailboxes');
    }
};
