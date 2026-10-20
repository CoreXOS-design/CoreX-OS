<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AT-463 / spec §62 - the tick-box sensitivity is the share of a box's inside that must carry ink. It defaulted to 0.35
 * (a box filled in solid); a hand tick covers 5-20 %, so the default is now 0.04. Every agency that ever pressed Save on the
 * Rental Inspections settings page stored the old 0.35 (the form posted the default back), and would still read no ticks.
 * Anything above 0.15 was calibrated for solid fills and cannot read a real tick: it goes back to "unset", i.e. the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('rental_inspection_settings')->where('omr_mark_threshold', '>', 0.15)->update(['omr_mark_threshold' => null]);
    }

    public function down(): void
    {
        // not reversible: the old values were a stale default, not a choice
    }
};
