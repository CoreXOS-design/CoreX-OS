<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 2026-08-19 (Johan) — dismissing an appointment must be honest and
 * accountable: the reason-picker modal already collects a reason (mandatory
 * in the UI — the Dismiss button is disabled until one is picked), but
 * CalendarController::dismiss() reads neither `completion_reason_code` nor
 * `completion_reason` from the request, so the reason is captured and thrown
 * away (.ai/audits/2026-08-18-calendar-action-buttons-by-event-type.md §8/
 * the follow-up Dismiss investigation).
 *
 * Deliberately named `dismissal_*`, NOT `completion_reason_code`/
 * `completion_reason` — those exact names already exist on
 * calendar_event_class_settings (migration 2026_05_06_000001) and mean
 * something else entirely (a per-CLASS config field, unused by this flow).
 * Reusing the name on calendar_events would read as the same concept when
 * it is not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->string('dismissal_reason_code', 50)->nullable()->after('status');
            $table->text('dismissal_reason_notes')->nullable()->after('dismissal_reason_code');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn(['dismissal_reason_code', 'dismissal_reason_notes']);
        });
    }
};
