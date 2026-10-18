<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §14.29 — the audit log of a crew's STANDING
 * link (the crew page): issued / regenerated / emailed / revoked by the office,
 * opened / job_opened / action by the crew. Append-only (no deleted_at, no
 * updated_at): a log is evidence, never edited. Actions the crew takes on a
 * card also write that card's own history line; this is the crew-level view.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_crew_link_events')) {
            return;
        }

        Schema::create('rental_crew_link_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_crew_id')->constrained('rental_crews', indexName: 'rcle_crew_fk')->cascadeOnDelete();
            $table->foreignId('token_id')->nullable()->constrained('rental_secure_access_tokens', indexName: 'rcle_token_fk')->nullOnDelete();
            // issued | emailed | opened | job_opened | revoked | regenerated | action
            $table->string('event', 20);
            $table->foreignId('rental_job_card_id')->nullable()->constrained('rental_job_cards', indexName: 'rcle_card_fk')->nullOnDelete();
            $table->string('note', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users', indexName: 'rcle_actor_fk')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['rental_crew_id', 'created_at'], 'rcle_crew_created_idx');
            $table->index(['token_id', 'event', 'created_at'], 'rcle_token_event_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_crew_link_events');
    }
};
