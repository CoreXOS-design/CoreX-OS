<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-107 Viewing Pack — Step 8: calendar tie-in scheduling fields.
 *
 *  - viewing_packs.tour_at — the agent-set date/time of the viewing tour. The
 *    linked CalendarEvent's event_date is sourced from this.
 *  - agencies.viewing_pack_default_duration_minutes — per-agency default viewing
 *    duration (configurability hard rule; NULL = default 60), resolved in
 *    ViewingPackCalendarService::durationFor(). Mirrors the Step 5b DPI setting.
 *
 * The calendar_event_id link column already exists (Step 2). Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('viewing_packs', function (Blueprint $table) {
            $table->dateTime('tour_at')->nullable()->after('calendar_event_id');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('viewing_pack_default_duration_minutes')->nullable()->after('viewing_pack_redaction_dpi');
        });
    }

    public function down(): void
    {
        Schema::table('viewing_packs', function (Blueprint $table) {
            $table->dropColumn('tour_at');
        });
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('viewing_pack_default_duration_minutes');
        });
    }
};
