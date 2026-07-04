<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-178 — Event Reminders.
 *
 * Wires the previously-scaffolded reminder infrastructure into a real,
 * per-user / per-channel / per-occurrence delivery system:
 *
 *  - calendar_events.reminder_channels        — per-event channel set (popup/email)
 *  - calendar_reminders_log.occurrence_key    — recurring-occurrence discriminator +
 *                                               the UNIQUE idempotency contract
 *  - calendar_event_class_settings.default_*  — agency default reminder per event class
 *  - agency_contact_settings.calendar_reminder_lead_options — agency-configurable
 *                                               lead-time option list (no hardcoding)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            // Per-event channels, e.g. ["popup"] or ["popup","email"]. Null = fall
            // back to class default → system default (popup on, email off).
            $table->json('reminder_channels')->nullable()->after('reminder_offsets');
        });

        Schema::table('calendar_reminders_log', function (Blueprint $table) {
            // 'single' for a non-recurring event; 'YYYYMMDD' of the occurrence start
            // for a recurring occurrence. NOT NULL + default so the UNIQUE index below
            // never trips MySQL's "NULLs are distinct" behaviour (which would let
            // duplicate sends slip through for non-recurring events).
            $table->string('occurrence_key', 16)->default('single')->after('offset_minutes');

            // Snooze: when set + in the future, the popup toast hides this reminder
            // until the timestamp passes (10-minute snooze). Independent of read_at.
            $table->dateTime('snoozed_until')->nullable()->after('read_at');

            // Exactly-once delivery contract: one send per (event,user,channel,offset,
            // occurrence). A duplicate insert violates this and is caught+skipped, so a
            // double scheduler tick can never double-send.
            $table->unique(
                ['calendar_event_id', 'user_id', 'channel', 'offset_minutes', 'occurrence_key'],
                'cal_reminder_once_idx'
            );
        });

        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            // Agency default reminder per event class. Null = no class default (fall to
            // system default). Offsets: array of minutes-before. Channels: subset of
            // ['popup','email'].
            $table->json('default_reminder_offsets')->nullable()->after('daily_digest_roles');
            $table->json('default_reminder_channels')->nullable()->after('default_reminder_offsets');
        });

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            // Agency-configurable lead-time option list surfaced in the event form
            // selector, e.g. [0,5,10,15,30,60,120,1440]. Null = model default constant.
            $table->json('calendar_reminder_lead_options')->nullable()->after('calendar_max_expansion_days');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropColumn('reminder_channels');
        });

        Schema::table('calendar_reminders_log', function (Blueprint $table) {
            $table->dropUnique('cal_reminder_once_idx');
            $table->dropColumn(['occurrence_key', 'snoozed_until']);
        });

        Schema::table('calendar_event_class_settings', function (Blueprint $table) {
            $table->dropColumn(['default_reminder_offsets', 'default_reminder_channels']);
        });

        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('calendar_reminder_lead_options');
        });
    }
};
