<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-Core-Matches, share-history piece — "the buyer opened the link" is a
 * DIFFERENT event from "the agent shared the link" and must never be
 * conflated with one (Johan's own framing). An agent seeing "sent three
 * times, never opened" is a real, valuable signal a share count alone
 * can't give — but it is not evidence of what was shown, so it gets its
 * own table, not a column bolted onto contact_match_shares.
 *
 * Recorded from the public, unauthenticated shared-match page
 * (SharedMatchController) — there is no session to distinguish an agent
 * previewing their own link from the buyer actually opening it, so every
 * hit counts. Kept deliberately minimal (no IP, no user agent) — nothing
 * beyond what answers "did they open it, and when."
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_match_link_opens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('contact_match_id')->constrained('contact_matches')->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('created_at');
            $table->softDeletes();

            $table->index(['contact_match_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_match_link_opens');
    }
};
