<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-241 — a calendar_events row is legitimately agency-less (nullable
 * agency_id since 2026_03_31_300001): a super user / global owner with no
 * agency context creates a personal or global event. Its child rows
 * (calendar_event_links, calendar_event_invitations) were made NOT NULL by
 * the 2026_05_23 batch, which BACKFILLED from the parent on the assumption
 * that every event has an agency. It does not.
 *
 * A child row cannot meaningfully belong to an agency its parent event does
 * not (the whole read model keys visibility off the EVENT's agency, never
 * the link's). So the child agency_id must MIRROR the parent — including
 * NULL. Leaving them NOT NULL forced the create flow to stamp a sentinel
 * (agency 1 via `?: 1`, or 0 via a mechanical `?: 0`) into the FK: the first
 * mis-files the event into a tenant the user does not belong to (cross-tenant
 * leak + the AT-241 invisibility, because the read-side agency-isolation guard
 * then rejects it); the second is a straight FK-1452 500.
 *
 * Fix the root cause: widen the child FK to nullable so it can faithfully
 * mirror the nullable parent. Existing non-NULL rows are untouched; the FK
 * (nullOnDelete) is preserved.
 */
return new class extends Migration
{
    /** @var array<string,string> table => FK-referenced parent event column */
    private array $tables = [
        'calendar_event_links'       => 'calendar_event_id',
        'calendar_event_invitations' => 'event_id',
    ];

    public function up(): void
    {
        foreach (array_keys($this->tables) as $table) {
            Schema::table($table, function ($t) use ($table) {
                $t->dropForeign(['agency_id']);
            });

            // MODIFY to nullable (raw — Doctrine-less, matches the 2026_05_23 style).
            DB::statement("ALTER TABLE `{$table}` MODIFY `agency_id` BIGINT UNSIGNED NULL");

            Schema::table($table, function ($t) {
                $t->foreign('agency_id')->references('id')->on('agencies')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table => $parentColumn) {
            // Re-backfill any NULLs from the parent event so the NOT NULL
            // restore cannot fail. Rows whose parent is itself agency-less
            // cannot be represented under NOT NULL — drop them (soft-deleted
            // children of a global event; no tenant data lost).
            DB::statement(
                "UPDATE `{$table}` t JOIN `calendar_events` p ON p.id = t.`{$parentColumn}` "
                . "SET t.agency_id = p.agency_id WHERE t.agency_id IS NULL AND p.agency_id IS NOT NULL"
            );
            DB::table($table)->whereNull('agency_id')->delete();

            Schema::table($table, function ($t) {
                $t->dropForeign(['agency_id']);
            });
            DB::statement("ALTER TABLE `{$table}` MODIFY `agency_id` BIGINT UNSIGNED NOT NULL");
            Schema::table($table, function ($t) {
                $t->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            });
        }
    }
};
