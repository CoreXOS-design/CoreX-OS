<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 - Road to Sold v2 phase 3: the task completions become the system of record for points (road-to-sold.md section 7, 14 phase 3).
 *
 * rts_task_completions gains what the points readers need to read it exactly as they read the old daily_activity_entries:
 *   activity_date  the agency-local calendar day the credit belongs to (the old column of the same name)
 *   period         that day's month, "YYYY-MM" (the old column of the same name)
 *   legacy_source  for a row that came from the old engine (migrated history, or a new credit the old detectors still raise):
 *                  the old source - manual / auto_calendar / auto_instant / auto_other. It is what keeps the MANUAL vs AUTOMATIC split
 *                  (two colours, Johan 21 Oct 2026) exact across the move.
 *   legacy_entry_id the daily_activity_entries row it was copied or mirrored from (unique: one entry, one completion)
 *
 * rts_cutovers records, once per agency and track, WHEN the agency moved to the new engine and what the move carried (counts and
 * points before and after) - never edited afterwards (spec 7.4: "recorded once, never edited"). An agency with a cut-over row reads its
 * points from the completions; an agency without one is untouched.
 *
 * Nothing is dropped or rewritten: daily_activity_entries, activity_definitions and the calendar mappings stay exactly as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_task_completions', function (Blueprint $table) {
            if (! Schema::hasColumn('rts_task_completions', 'activity_date')) {
                $table->date('activity_date')->nullable()->after('period_start');
            }
            if (! Schema::hasColumn('rts_task_completions', 'period')) {
                $table->string('period', 7)->nullable()->after('activity_date');
            }
            if (! Schema::hasColumn('rts_task_completions', 'legacy_source')) {
                $table->string('legacy_source', 20)->nullable()->after('source');
            }
            if (! Schema::hasColumn('rts_task_completions', 'legacy_entry_id')) {
                $table->unsignedBigInteger('legacy_entry_id')->nullable()->after('legacy_source');
                $table->unique('legacy_entry_id', 'rts_task_completions_legacy_entry_unique');
            }
            $table->index(['agency_id', 'user_id', 'period'], 'rts_task_completions_user_period_idx');
            $table->index(['agency_id', 'activity_date'], 'rts_task_completions_date_idx');
        });

        if (! Schema::hasTable('rts_cutovers')) {
            Schema::create('rts_cutovers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained('agencies');
                $table->string('track', 20)->default('sales');
                $table->dateTime('cut_over_at');
                $table->json('report')->nullable();
                $table->timestamps();

                $table->unique(['agency_id', 'track'], 'rts_cutovers_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('rts_cutovers');
        Schema::table('rts_task_completions', function (Blueprint $table) {
            foreach (['rts_task_completions_user_period_idx', 'rts_task_completions_date_idx'] as $idx) {
                try { $table->dropIndex($idx); } catch (\Throwable) {}
            }
            try { $table->dropUnique('rts_task_completions_legacy_entry_unique'); } catch (\Throwable) {}
            foreach (['legacy_entry_id', 'legacy_source', 'period', 'activity_date'] as $c) {
                if (Schema::hasColumn('rts_task_completions', $c)) {
                    $table->dropColumn($c);
                }
            }
        });
    }
};
