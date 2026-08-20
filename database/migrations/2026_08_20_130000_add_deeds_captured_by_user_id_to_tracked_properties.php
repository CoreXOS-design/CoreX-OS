<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Deeds Capture data-scope build (Johan, 2026-08-20): "the user who scraped it. thats the
 * person who will go to deeds and look for their scraped stock." tracked_properties had no
 * column recording who performed the scrape — the only column that even looked like it
 * (promoted_by_user_id) is structurally NULL for every row the Deeds Capture screen shows,
 * since that screen explicitly excludes promoted records. Same failure shape as the MIC
 * captured_by_user_id incident: a plausible-looking column that is meaningless for the
 * actual list being scoped.
 *
 * The real signal already exists, just not as a column: every deeds capture (create OR
 * enrich) fires TrackedPropertyCreated/TrackedPropertyEnriched with actorUserId + a
 * source_type context, logged by LogAgentActivity into agent_activity_events. Verified live
 * (agency 1, 2026-08-20): 85/85 currently-eligible Deeds Capture rows resolve to a real
 * actor via that log, 0 NULL. This migration promotes that signal to a real column so
 * every future list/count query is a plain WHERE, not a per-request JSON-log join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->foreignId('deeds_captured_by_user_id')->nullable()->after('deeds_captured_at')
                ->constrained('users')->nullOnDelete();
            $table->index('deeds_captured_by_user_id', 'idx_tracked_props_deeds_captured_by');
        });

        // Backfill from the activity log: for every tracked_properties row, take the actor
        // of its MOST RECENT deeds_capture-sourced tracked_property.created/.enriched event.
        // "Most recent" mirrors the existing precedent in DeedsCaptureController::index()'s
        // $fieldChangesByTp build (source_chain's latest deeds entry, not the latest overall) —
        // same reasoning: whoever scraped it last is the person who'll come looking for it.
        DB::statement(<<<'SQL'
            UPDATE tracked_properties tp
            JOIN (
                -- MIN(user_id) is a deterministic, arbitrary tie-break for the rare case of
                -- two different actors logging a deeds_capture event at the identical
                -- microsecond timestamp — never happens in practice, but keeps this backfill
                -- single-valued per subject_id instead of leaving it to engine-dependent
                -- UPDATE...JOIN duplicate-match behaviour.
                SELECT aae.subject_id, MIN(aae.user_id) AS user_id
                FROM agent_activity_events aae
                JOIN (
                    SELECT subject_id, MAX(occurred_at) AS max_occurred
                    FROM agent_activity_events
                    WHERE subject_type = 'App\\Models\\Prospecting\\TrackedProperty'
                      AND event_type IN ('tracked_property.created', 'tracked_property.enriched')
                      AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.source_type')) = 'deeds_capture'
                    GROUP BY subject_id
                ) latest ON latest.subject_id = aae.subject_id AND latest.max_occurred = aae.occurred_at
                WHERE aae.subject_type = 'App\\Models\\Prospecting\\TrackedProperty'
                  AND aae.event_type IN ('tracked_property.created', 'tracked_property.enriched')
                  AND JSON_UNQUOTE(JSON_EXTRACT(payload, '$.source_type')) = 'deeds_capture'
                GROUP BY aae.subject_id
            ) actor ON actor.subject_id = tp.id
            SET tp.deeds_captured_by_user_id = actor.user_id
            WHERE tp.deeds_captured_by_user_id IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('tracked_properties', function (Blueprint $table) {
            $table->dropForeign(['deeds_captured_by_user_id']);
            $table->dropIndex('idx_tracked_props_deeds_captured_by');
            $table->dropColumn('deeds_captured_by_user_id');
        });
    }
};
