<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_reminders_log', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->after('calendar_event_id');
        });

        DB::statement(<<<'SQL'
UPDATE calendar_reminders_log t JOIN calendar_events p ON p.id=t.calendar_event_id SET t.agency_id=p.agency_id WHERE t.agency_id IS NULL
SQL);

        // wave3b-fallback: single-agency dev DB safety net.
        if (DB::table('agencies')->count() === 1) {
            $aid = (int) DB::table('agencies')->value('id');
            DB::statement("UPDATE `calendar_reminders_log` SET agency_id={$aid} WHERE agency_id IS NULL");
        }

        $nullCount = DB::table('calendar_reminders_log')->whereNull('agency_id')->count();
        if ($nullCount > 0) {
            throw new \RuntimeException(
                "Migration aborted: {$nullCount} calendar_reminders_log rows still have NULL agency_id after backfill."
            );
        }

        DB::statement('ALTER TABLE `calendar_reminders_log` MODIFY agency_id BIGINT UNSIGNED NOT NULL');

        Schema::table('calendar_reminders_log', function (Blueprint $table) {
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->index(['agency_id'], 'calendar_reminders_log_agency_id_idx');
        });
    }

    public function down(): void
    {
        Schema::table('calendar_reminders_log', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
            $table->dropIndex('calendar_reminders_log_agency_id_idx');
            $table->dropColumn('agency_id');
        });
    }
};