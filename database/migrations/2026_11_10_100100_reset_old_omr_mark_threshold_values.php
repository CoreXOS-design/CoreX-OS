<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-463 / spec §62 - the tick-box sensitivity is the share of a box's inside that must carry ink. It defaulted to 0.35
 * (a box filled in solid); a hand tick covers 5-20 %, so the default is now 0.04. Every agency that ever pressed Save on the
 * Rental Inspections settings page stored the old 0.35 (the form posted the default back), and would still read no ticks.
 * Anything above 0.15 was calibrated for solid fills and cannot read a real tick: it goes back to "unset", i.e. the default.
 */
return new class extends Migration
{
    /** Holds the values this reset nulled, so down() can put them back (audit fix 2026-10-20). */
    private const BACKUP_TABLE = 'rental_inspection_settings_omr_threshold_backup';

    public function up(): void
    {
        if (!Schema::hasTable(self::BACKUP_TABLE)) {
            Schema::create(self::BACKUP_TABLE, function ($table) {
                $table->unsignedBigInteger('settings_id')->primary();
                $table->decimal('old_value', 8, 4);
                $table->timestamp('created_at')->nullable();
            });
        }

        // Remember each value before it is reset (insertOrIgnore: a re-run never overwrites the first record).
        $rows = DB::table('rental_inspection_settings')->where('omr_mark_threshold', '>', 0.15)->get(['id', 'omr_mark_threshold']);
        foreach ($rows as $row) {
            DB::table(self::BACKUP_TABLE)->insertOrIgnore([
                'settings_id' => $row->id, 'old_value' => $row->omr_mark_threshold, 'created_at' => now(),
            ]);
        }

        DB::table('rental_inspection_settings')->where('omr_mark_threshold', '>', 0.15)->update(['omr_mark_threshold' => null]);
    }

    /**
     * Puts back what up() reset, for rows still unset. A row an agency has set again since is left alone. On an
     * environment where up() ran before the backup existed there is nothing recorded and nothing to restore.
     */
    public function down(): void
    {
        if (!Schema::hasTable(self::BACKUP_TABLE)) {
            return;
        }

        foreach (DB::table(self::BACKUP_TABLE)->get() as $row) {
            DB::table('rental_inspection_settings')
                ->where('id', $row->settings_id)
                ->whereNull('omr_mark_threshold')
                ->update(['omr_mark_threshold' => $row->old_value]);
        }

        Schema::dropIfExists(self::BACKUP_TABLE);
    }
};
