<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 Part A2 — per-view engagement log.
 *
 * One row per "viewing session" — the initial GET creates a row, the JS
 * tracking beacon updates duration_seconds / scroll_depth_pct /
 * sections_viewed_json over time. POPIA-respectful: ip_address is masked
 * by default (/24 for IPv4, /48 for IPv6) unless the agency has opted into
 * full IP capture for fraud cases.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('presentation_snapshot_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('snapshot_link_id')->constrained('presentation_snapshot_links')->cascadeOnDelete();
            $table->timestamp('viewed_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('fingerprint', 128);
            $table->string('referrer_url', 500)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->unsignedTinyInteger('scroll_depth_pct')->nullable();
            $table->json('sections_viewed_json')->nullable();
            $table->boolean('is_first_view')->default(false);
            $table->boolean('flagged_fingerprint_mismatch')->default(false);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['snapshot_link_id', 'viewed_at']);
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('presentation_snapshot_views');
    }
};
