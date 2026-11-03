<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Road to Sold v4, conveyor tiles (road-to-sold.md 23.24): the next-step windows behind "late" and the day-feed switch. Five agency settings on rts_settings:
 *   due_claimed_days    working days after the claim to find the owner         (standard 5)
 *   due_owner_days      working days after the owner is found to make contact  (standard 3)
 *   due_contacted_days  calendar days after the contact to book the evaluation (standard 7)
 *   due_held_days       working days after the evaluation to give the result    (standard 2)
 *   fill_from_claims_first  the day's list is filled from the agent's own claims before any other source (standard on)
 * Additive; a re-run changes nothing. Until it has run the code uses the standard numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rts_settings', function (Blueprint $t) {
            foreach (['due_claimed_days' => 5, 'due_owner_days' => 3, 'due_contacted_days' => 7, 'due_held_days' => 2] as $col => $default) {
                if (! Schema::hasColumn('rts_settings', $col)) {
                    $t->unsignedSmallInteger($col)->default($default);
                }
            }
            if (! Schema::hasColumn('rts_settings', 'fill_from_claims_first')) {
                $t->boolean('fill_from_claims_first')->default(true);
            }
        });
    }

    public function down(): void
    {
        foreach (['due_claimed_days', 'due_owner_days', 'due_contacted_days', 'due_held_days', 'fill_from_claims_first'] as $col) {
            if (Schema::hasColumn('rts_settings', $col)) {
                Schema::table('rts_settings', function (Blueprint $t) use ($col) {
                    $t->dropColumn($col);
                });
            }
        }
    }
};
