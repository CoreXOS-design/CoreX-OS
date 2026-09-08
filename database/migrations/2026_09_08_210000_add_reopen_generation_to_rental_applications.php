<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reopen/resubmit, 2026-09-08 — Johan: an agent must be able to send a
 * returned application back to the applicant to fix an answer and re-sign,
 * WITHOUT losing the original signed submission as evidence. `current_generation`
 * is "which submission round is live" — starts at 1 the moment an application
 * is first created (nothing sealed yet is round 0 conceptually, but the column
 * defaults to 1 so a never-reopened application, the overwhelming common case,
 * needs no extra join to answer "what generation is this" — see
 * RentalApplicationGeneration's own docblock for why the row-per-generation
 * table still exists even though most applications only ever have one).
 *
 * `reopened_at` / `reopened_by_user_id` / `reopened_note` — who sent it back
 * and why, kept on the live row (not just the status-history trail) so the
 * review screen can show "reopened by X on Y: note" without a join, mirroring
 * how submitted_for_approval_at is already surfaced this way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->unsignedInteger('current_generation')->default(1)->after('status');
            $table->timestamp('reopened_at')->nullable()->after('submitted_for_approval_at');
            $table->foreignId('reopened_by_user_id')->nullable()->after('reopened_at')->constrained('users')->nullOnDelete();
            $table->text('reopened_note')->nullable()->after('reopened_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_applications', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reopened_by_user_id');
            $table->dropColumn(['current_generation', 'reopened_at', 'reopened_note']);
        });
    }
};
