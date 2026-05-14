<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contact_outreach_log — per-contact timeline of seller-outreach events
 * (sent / clicked / opted_out). Written by the
 * AppendOutreachToContactTimeline listener (Prompt 03).
 *
 * Option B chosen per Prompt 03 pre-flight: CoreX has no existing
 * contact_activities / contact_communications table. A dedicated outreach
 * log keeps scope clean and avoids hijacking a future generic activity
 * table that doesn't exist yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_outreach_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('contact_id');
            $table->unsignedBigInteger('send_id')->nullable();
            $table->enum('event_kind', ['sent', 'clicked', 'opted_out']);
            $table->timestamp('occurred_at');
            $table->string('summary', 255);
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->timestamps();

            $table->foreign('agency_id')
                ->references('id')->on('agencies')
                ->cascadeOnDelete();
            $table->foreign('contact_id')
                ->references('id')->on('contacts')
                ->cascadeOnDelete();
            $table->foreign('send_id')
                ->references('id')->on('seller_outreach_sends')
                ->nullOnDelete();
            $table->foreign('actor_user_id')
                ->references('id')->on('users')
                ->nullOnDelete();

            $table->index(['agency_id', 'contact_id', 'occurred_at'], 'contact_outreach_log_contact_idx');
            $table->index(['agency_id', 'event_kind'], 'contact_outreach_log_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contact_outreach_log', function (Blueprint $table) {
            $table->dropForeign(['actor_user_id']);
            $table->dropForeign(['send_id']);
            $table->dropForeign(['contact_id']);
            $table->dropForeign(['agency_id']);
            $table->dropIndex('contact_outreach_log_contact_idx');
            $table->dropIndex('contact_outreach_log_kind_idx');
        });
        Schema::dropIfExists('contact_outreach_log');
    }
};
