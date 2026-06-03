<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SPINE-1 — extend the action-catalogue table additively so it serves BOTH
 * calendar AND instant trigger kinds. Calendar engine code (M6.3/M6.4)
 * is intentionally untouched — the new columns default to 'calendar' so
 * existing rows + existing queries keep working unchanged.
 *
 * Table-reconciliation decision (per SPINE-1 prompt + audit):
 * - Option A: add trigger_kind to activity_definition_calendar_classes.   ← CHOSEN
 * - Option B: rename to activity_definition_actions + migrate 4 rows.
 * - Option C: separate activity_definition_instant_actions table.
 *
 * Chose A because it leaves M6.3 ProvisionalPointService, M6.4
 * AutoRevoke command, M6.2 admin controller, and M6.2-FIX seeder
 * literally untouched (the calendar queries continue to read this
 * table with no changes to their WHERE clauses, and the default
 * trigger_kind='calendar' on existing + new calendar rows preserves
 * their behaviour). The cost is naming debt — the table name
 * "activity_definition_calendar_classes" now also stores instant
 * mappings — which a future SPINE-N pure Schema::rename can clean up
 * once the full spine is wired and proven. Naming debt < risking a
 * working calendar engine the night before live deploy.
 *
 * Additions to activity_definition_calendar_classes:
 *   + trigger_kind   varchar(16) default 'calendar' — 'calendar' | 'instant'
 *   + slug           varchar(64) nullable           — e.g. 'contact.captured'
 *                                                     (NULL for calendar rows;
 *                                                     populated for instant rows)
 *   + subject_type   varchar(100) nullable          — model class the action
 *                                                     applies to (e.g.
 *                                                     'App\\Models\\Contact') —
 *                                                     advisory for now,
 *                                                     consumed by future admin UI
 *   + index on (agency_id, trigger_kind, is_active) for kind-scoped lookups
 *   + unique on (agency_id, slug, deleted_at) for instant lookups
 *     (multi-NULL safe — calendar rows have slug=NULL; MySQL allows
 *      multiple NULLs in a unique index)
 *
 * Additions to daily_activity_entries:
 *   + subject_type   varchar(100) nullable          — what was credited
 *   + subject_id     unsignedBigInteger nullable    — id of the subject
 *   + index on (subject_type, subject_id) for credit lookups + future revoke
 *
 * Backfill: not required. Existing calendar rows get trigger_kind='calendar'
 * via the column default at INSERT time; existing rows are read via
 * the SAME WHERE-clause shape M6.3/M6.4 use today (no trigger_kind
 * filter), which still returns them.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('activity_definition_calendar_classes', function (Blueprint $t) {
            $t->string('trigger_kind', 16)->default('calendar')->after('activity_definition_id');
            $t->string('slug', 64)->nullable()->after('trigger_kind');
            $t->string('subject_type', 100)->nullable()->after('slug');
            $t->index(['agency_id', 'trigger_kind', 'is_active'], 'adcc_agency_kind_active_idx');
            $t->unique(['agency_id', 'slug', 'deleted_at'], 'adcc_agency_slug_unique');
        });

        // Defensive backfill: in case any pre-existing row was inserted with
        // an explicit NULL (rare — defaults make this a no-op), set
        // trigger_kind='calendar' so the kind-scoped queries always match.
        DB::table('activity_definition_calendar_classes')
            ->whereNull('trigger_kind')
            ->update(['trigger_kind' => 'calendar']);

        Schema::table('daily_activity_entries', function (Blueprint $t) {
            $t->string('subject_type', 100)->nullable()->after('calendar_event_id');
            $t->unsignedBigInteger('subject_id')->nullable()->after('subject_type');
            $t->index(['subject_type', 'subject_id'], 'dae_subject_idx');
        });
    }

    public function down(): void
    {
        Schema::table('daily_activity_entries', function (Blueprint $t) {
            $t->dropIndex('dae_subject_idx');
            $t->dropColumn(['subject_type', 'subject_id']);
        });

        Schema::table('activity_definition_calendar_classes', function (Blueprint $t) {
            $t->dropUnique('adcc_agency_slug_unique');
            $t->dropIndex('adcc_agency_kind_active_idx');
            $t->dropColumn(['trigger_kind', 'slug', 'subject_type']);
        });
    }
};
