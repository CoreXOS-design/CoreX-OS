<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Build 5 — snapshot persistence + freshness window.
 *
 * Two pieces:
 *   (1) presentation_versions.snapshot_payload — JSON, nullable. Holds the
 *       FULL AnalysisDataService::compile() result frozen at publish time.
 *       The public view reads from this column instead of recompiling every
 *       page load, so sellers see what was true on publish day. The legacy
 *       `data_snapshot_json` (Build 1) only held blueprint metadata; the
 *       legacy `computed_json` was an inconsistent older snapshot. Both
 *       remain for backwards compatibility but are no longer the
 *       authoritative source for new publishes.
 *
 *   (2) agencies.presentations_freshness_days — int, default 90. When a
 *       seller opens a share link older than this, the public view shows a
 *       polite CTA inviting them to ask the agent for a revised analysis.
 *       Per-agency configurable so a fast-moving market can shorten the
 *       window without code change.
 *
 * `snapshot_taken_at` records WHEN the snapshot was frozen. Republishing
 * refreshes both the payload and this timestamp, so the freshness window
 * restarts. Stored separately from `published_at` because a future
 * "preview publish" or "draft snapshot" flow might desync these.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('presentation_versions', function (Blueprint $table) {
            if (!Schema::hasColumn('presentation_versions', 'snapshot_payload')) {
                $table->json('snapshot_payload')->nullable()->after('enabled_sections_json')
                      ->comment('Build 5 — full compiled report payload frozen at publish. Public view reads from this; live compile is fallback only.');
            }
            if (!Schema::hasColumn('presentation_versions', 'snapshot_taken_at')) {
                $table->timestamp('snapshot_taken_at')->nullable()->after('snapshot_payload')
                      ->comment('Build 5 — when snapshot_payload was last frozen. Drives the freshness window calc.');
            }
        });

        Schema::table('agencies', function (Blueprint $table) {
            if (!Schema::hasColumn('agencies', 'presentations_freshness_days')) {
                $table->unsignedSmallInteger('presentations_freshness_days')
                      ->default(90)
                      ->after('presentations_default_show_pricing_strategy')
                      ->comment('Build 5 — public view shows a "request revised analysis" CTA when the snapshot is older than this many days.');
            }
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            if (Schema::hasColumn('agencies', 'presentations_freshness_days')) {
                $table->dropColumn('presentations_freshness_days');
            }
        });
        Schema::table('presentation_versions', function (Blueprint $table) {
            foreach (['snapshot_payload', 'snapshot_taken_at'] as $col) {
                if (Schema::hasColumn('presentation_versions', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
