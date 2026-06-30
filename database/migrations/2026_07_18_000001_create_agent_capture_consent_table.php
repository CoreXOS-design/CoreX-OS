<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-136 — per-agent WhatsApp capture consent.
 *
 * SEPARATE from the AT-125 CONTACT marketing opt-out (which stops SENDING, POPIA).
 * This layer controls INGESTION of message BODIES: an agent decides, per matched
 * contact, whether their WhatsApp chats with that contact are archived.
 *
 * Floor (AT-122/133): a WhatsApp number with NO CoreX contact match is NEVER
 * ingested — no row here, no envelope, no body. Only MATCHED contacts get a row.
 * A new match defaults to 'pending' (decide before bodies flow — safest for the
 * personal-chat boundary). The envelope of a matched contact is still archived
 * (FICA floor); only the BODY is gated on status='opted_in'.
 *
 * status: pending → opted_in | opted_out. Opt-out carries a reason + is surfaced
 * to admin/CO (declaration, never message content) as the FICA backstop; admin
 * may FLAG a contact for opt-in (the business call) but cannot override the agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_capture_consent', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('agent_user_id')->constrained('users', 'id', 'acc_agent_fk');
            $table->foreignId('contact_id')->constrained('contacts', 'id', 'acc_contact_fk');

            // pending → opted_in | opted_out
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();                 // agent's opt-out reason
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decided_by_user_id')->nullable()
                ->constrained('users', 'id', 'acc_decided_by_fk')->nullOnDelete();

            // Admin/CO "flag for opt-in" — the business call. Sees, does NOT override.
            $table->boolean('admin_flagged')->default(false);
            $table->text('admin_flag_note')->nullable();
            $table->foreignId('admin_flag_by_user_id')->nullable()
                ->constrained('users', 'id', 'acc_flag_by_fk')->nullOnDelete();
            $table->timestamp('admin_flagged_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'agent_user_id', 'contact_id'], 'acc_agency_agent_contact_uq');
            $table->index(['agency_id', 'agent_user_id', 'status'], 'acc_agent_status_idx');
            $table->index(['agency_id', 'status'], 'acc_agency_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_capture_consent');
    }
};
