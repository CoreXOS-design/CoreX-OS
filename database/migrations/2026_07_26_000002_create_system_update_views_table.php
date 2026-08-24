<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * System Updates — per-user acknowledgement.
 *
 * Spec: .ai/specs/system-updates.md §4.2.
 *
 * One row per (update, user), written ONLY once the user has actually closed the
 * pop-up. Absence of a row means "not yet acknowledged" — the simplest possible
 * truth, and the one that survives a browser crash correctly: close the tab
 * mid-modal and nothing is written, so it is still pending next load, which is
 * exactly right because it was never acknowledged.
 *
 * Like user_tour_progress, this is intentionally PERSONAL UI STATE — keyed by
 * user_id only, not tenant-owned, so it carries no agency_id (spec §3). Writes are
 * self-scoped from auth()->id(); the endpoint takes no user id as input, so a user
 * can never mark another user's row.
 *
 * No SoftDeletes: the re-notify path uses the system_updates.notify_reset_at
 * watermark rather than deleting rows, so nothing here is ever destroyed and the
 * "who saw the original" audit is permanent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_update_views', function (Blueprint $table) {
            $table->id();

            $table->foreignId('system_update_id')->constrained('system_updates')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            $table->timestamp('dismissed_at');
            $table->timestamps();

            $table->unique(['system_update_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_update_views');
    }
};
