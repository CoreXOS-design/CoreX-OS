<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5 Part A2 — retroactive view-row attribution.
 *
 * Pre-lead-capture views are anonymous. When the lead submits the form,
 * we backfill teaser_lead_id on every unattributed view row for that
 * snapshot link with the same server fingerprint — so the analytics
 * "this lead spent N minutes on the page before converting" question
 * has a clean answer.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('presentation_snapshot_views', function (Blueprint $table) {
            if (!Schema::hasColumn('presentation_snapshot_views', 'teaser_lead_id')) {
                $table->foreignId('teaser_lead_id')
                    ->nullable()
                    ->after('snapshot_link_id')
                    ->constrained('presentation_teaser_leads')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('presentation_snapshot_views', function (Blueprint $table) {
            if (Schema::hasColumn('presentation_snapshot_views', 'teaser_lead_id')) {
                $table->dropForeign(['teaser_lead_id']);
                $table->dropColumn('teaser_lead_id');
            }
        });
    }
};
