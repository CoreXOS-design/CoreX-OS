<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, Johan's ruling 1 — only a branch manager or admin may
 * ever move a buyer between agents; an agent can never reassign, even to
 * themselves. Ruling 3 (build task) — every move records who moved it,
 * from whom, to whom, when, and why, and the why is required because
 * Johan's model is that the manager has a conversation with the agent
 * first. Nothing here is ever deleted — SoftDeletes from the first
 * migration, per the standing no-hard-deletes rule, even though an audit
 * row is never expected to need removing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_match_reassignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_match_id')->constrained('contact_matches')->cascadeOnDelete();
            $table->foreignId('from_agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('to_agent_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('moved_by_user_id')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['contact_match_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_reassignments');
    }
};
