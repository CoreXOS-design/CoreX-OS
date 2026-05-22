<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Part A1 — public share links for presentation snapshots.
 *
 * Each row is one tokenised URL the agent generated for a specific seller /
 * contact. The seller hits /p/{token} (no auth, public web middleware) and
 * gets the locked PresentationVersion the link is pinned to. Lock-by-design
 * defends against competitor abuse + locks the agent's narrative.
 *
 * Tracking columns:
 *   first_fingerprint   — server-derived fingerprint of the first opener
 *   first_viewed_at     — when that first view happened
 *   last_viewed_at      — every subsequent view bumps this
 *   view_count          — cumulative count
 *   flagged_at / reason — populated when a DIFFERENT fingerprint opens the
 *                          link (likely forwarded). We FLAG, not block.
 *
 * Refresh-request columns (Phase 7 fills these in) — kept here so the
 * public-view "Request refresh" form has a place to write to.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('presentation_snapshot_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('presentation_id')->constrained('presentations')->cascadeOnDelete();
            $table->foreignId('presentation_version_id')->constrained('presentation_versions')->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained('agencies');
            $table->string('token', 64)->unique();
            $table->enum('mode', ['full', 'teaser'])->default('full');
            $table->foreignId('recipient_contact_id')->nullable()->constrained('contacts')->nullOnDelete();
            $table->string('recipient_label', 200)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            // Engagement aggregates.
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('last_viewed_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);

            // Fingerprint + flagging.
            $table->string('first_fingerprint', 128)->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->string('flagged_reason', 200)->nullable();
            $table->timestamp('last_flag_notified_at')->nullable();

            // Refresh-request (Phase 7).
            $table->timestamp('refresh_requested_at')->nullable();
            $table->string('refresh_requested_by_name', 200)->nullable();
            $table->text('refresh_requested_message')->nullable();

            $table->timestamps();

            $table->index('presentation_id');
            $table->index('presentation_version_id');
            $table->index('recipient_contact_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_snapshot_links');
    }
};
