<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module 6 (M6.3) — relax the per-(definition,user,date) uniqueness on
 * `daily_activity_entries` so that the per-event auto-credit rows written
 * by ProvisionalPointService can coexist with the manual capture row AND
 * with each other (one per CalendarEvent).
 *
 * Old constraint: unique(activity_definition_id, user_id, activity_date)
 *                  — name: dae_def_user_date_unique (M6.1).
 *
 * New constraint: unique(activity_definition_id, user_id, activity_date,
 *                        calendar_event_id)
 *                  — name: dae_def_user_date_event_unique (M6.3).
 *
 * IMPORTANT — NULL semantics:
 *   MySQL treats NULL values as DISTINCT in UNIQUE constraints. That means
 *   the new constraint ALLOWS multiple rows where calendar_event_id IS NULL
 *   for the same (definition, user, date). The existing manual-capture code
 *   (DailyActivityController::store) uses DB::table(...)->updateOrInsert()
 *   keyed on (activity_definition_id, user_id, activity_date) — that
 *   application-layer pattern continues to enforce the "one manual row per
 *   def/user/date" invariant for the NULL-event-id row.
 *
 *   This relaxation ONLY enables per-event auto-credit rows (each with a
 *   non-NULL calendar_event_id) to coexist with that singleton manual row,
 *   and with each other. M6.5 owns the total-math; M6.4 owns confirm/revoke
 *   flow.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Order matters: MySQL refuses to drop the old unique index while
        // it is the only backing index for the activity_definition_id
        // foreign key (error 1553). Create the new composite unique first
        // — its leading column is also activity_definition_id, so it can
        // serve as the FK's backing index — then drop the old one.
        Schema::table('daily_activity_entries', function (Blueprint $table) {
            $table->unique(
                ['activity_definition_id', 'user_id', 'activity_date', 'calendar_event_id'],
                'dae_def_user_date_event_unique'
            );
        });

        Schema::table('daily_activity_entries', function (Blueprint $table) {
            $table->dropUnique('dae_def_user_date_unique');
        });
    }

    public function down(): void
    {
        // Reverse with the same FK-safety dance.
        Schema::table('daily_activity_entries', function (Blueprint $table) {
            $table->unique(
                ['activity_definition_id', 'user_id', 'activity_date'],
                'dae_def_user_date_unique'
            );
        });

        Schema::table('daily_activity_entries', function (Blueprint $table) {
            $table->dropUnique('dae_def_user_date_event_unique');
        });
    }
};
