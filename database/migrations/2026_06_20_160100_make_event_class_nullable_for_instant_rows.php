<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SPINE-1 follow-up — make event_class nullable on
 * activity_definition_calendar_classes.
 *
 * The original M6.2 migration (2026_06_16_120400) declared event_class as
 * NOT NULL — correct for calendar rows where the class slug IS the
 * lookup key. With SPINE-1's additive trigger_kind=instant rows, the
 * lookup key shifts to (trigger_kind, slug) and event_class is N/A:
 * instant rows have no calendar event_class. The seeder hit a 1048
 * (NOT NULL violation) trying to insert event_class=NULL.
 *
 * Calendar rows are unaffected — they continue to populate event_class
 * exactly as before; only the column constraint relaxes.
 *
 * Using raw ALTER (not Schema::table change()) to avoid pulling in
 * doctrine/dbal solely for a single column-modify.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE `activity_definition_calendar_classes` MODIFY `event_class` VARCHAR(64) NULL');
    }

    public function down(): void
    {
        // Down requires every NULL row to be removed first or the ALTER
        // will fail. Instant rows by definition have event_class=NULL,
        // so reversing this migration without first cleaning them up
        // would error. The clean-up is explicit and surgical: delete
        // instant rows before tightening the constraint back to NOT NULL.
        DB::table('activity_definition_calendar_classes')
            ->where('trigger_kind', 'instant')
            ->delete();
        DB::statement('ALTER TABLE `activity_definition_calendar_classes` MODIFY `event_class` VARCHAR(64) NOT NULL');
    }
};
