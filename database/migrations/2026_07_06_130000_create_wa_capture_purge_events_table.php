<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-183 — append-only audit of WhatsApp capture opt-out PURGES (POPIA evidence).
 *
 * When an agent declares a per-contact capture opt-out, the already-archived WA messages for
 * that agent↔contact pairing are purged (body content genuinely removed — the sanctioned
 * no-hard-delete exception for personal-data minimisation). This table records that the purge
 * happened — who opted out, when, the reason declaration, and HOW MANY messages were purged —
 * so compliance can demonstrate the removal. It NEVER stores message content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_capture_purge_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agent_user_id')->comment('the agent whose pairing was purged');
            $table->unsignedBigInteger('contact_id')->comment('the opted-out contact');
            $table->unsignedBigInteger('actor_user_id')->nullable()->comment('who declared the opt-out');
            $table->string('reason', 255)->nullable()->comment('the opt-out declaration (NOT message content)');
            $table->unsignedInteger('message_count')->default(0)->comment('messages whose body content was purged');
            $table->timestamp('purged_at');
            $table->timestamps();

            $table->index(['agency_id', 'agent_user_id', 'contact_id'], 'idx_wa_purge_pairing');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_capture_purge_events');
    }
};
