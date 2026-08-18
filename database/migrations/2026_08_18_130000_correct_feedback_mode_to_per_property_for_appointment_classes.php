<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 2026-08-18 (Johan) — "a presentation will always be on a property, so the
 * feedback is more relatable to the property than the contact." Backfills
 * feedback_mode='per_property' for viewing, property_evaluation and
 * listing_presentation — GLOBAL rows only (agency_id IS NULL), and only rows
 * still sitting on the pre-fix default (per_contact). Narrow and reversible
 * by design: never touches an agency's own override row (a deliberate
 * customisation is not this migration's business), never touches a class
 * already at the target value, and down() undoes exactly what up() did via
 * the mirrored predicate — no separate value-recording table needed.
 *
 * NOTE for anyone reading this later: on its own this migration does NOT fix
 * the calendar action-bar buttons — completion_behaviour (Capture Feedback vs
 * Mark Complete) is a separate column, already correct before this migration
 * ever ran, and the button set is derived live from it on every render (see
 * .ai/audits/2026-08-18-calendar-action-buttons-by-event-type.md). This
 * migration's job is narrower: Johan's decision that feedback belongs to the
 * PROPERTY being viewed/evaluated/presented, not the contact.
 *
 * database/seeders/CalendarEventClassSeeder.php is the canonical source for
 * NEW agencies going forward; this migration is the one-time backfill for
 * rows that already exist (seeders never run on a deploy — AT-162).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('calendar_event_class_settings')
            ->whereNull('agency_id')
            ->whereIn('event_class', ['viewing', 'property_evaluation', 'listing_presentation'])
            ->where('feedback_mode', 'per_contact')
            ->update(['feedback_mode' => 'per_property']);
    }

    public function down(): void
    {
        DB::table('calendar_event_class_settings')
            ->whereNull('agency_id')
            ->whereIn('event_class', ['viewing', 'property_evaluation', 'listing_presentation'])
            ->where('feedback_mode', 'per_property')
            ->update(['feedback_mode' => 'per_contact']);
    }
};
