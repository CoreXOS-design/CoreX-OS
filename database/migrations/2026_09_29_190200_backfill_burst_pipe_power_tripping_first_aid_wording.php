<?php

use App\Models\RentalFaultType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rentals-faults-work-orders.md §2.1/§3.2 — Johan's QA1 review,
 * 2026-09-29: the seeded Burst pipe / Power tripping defaults didn't
 * actually USE the {{main_water_valve_location}}/{{db_board_location}}
 * placeholders. RentalFaultType::DEFAULTS is corrected (this migration's
 * own commit); this backfills the rows ALREADY seeded onto every agency by
 * the create-table migration, so QA1's existing data isn't stuck on the
 * old wording until the next agency's seed runs.
 *
 * Scoped deliberately narrow: only rows that are STILL is_default=true AND
 * whose first_aid_steps text EXACTLY matches the old seeded content — an
 * agency that has already edited its own copy of either fault type is left
 * completely alone, per the standing "seedDefaultsFor() never overwrites an
 * agency's edit" discipline (RentalFaultType.php's own docblock).
 */
return new class extends Migration
{
    public function up(): void
    {
        $safetyLine = RentalFaultType::SAFETY_LINE;

        $oldBurstPipe = $safetyLine . "\n\n" . 'Close the main water valve. Turn off any electrical '
            . 'appliances near the water — do not touch them if already wet.';
        $newBurstPipe = $safetyLine . "\n\n" . 'Your main water valve is at: '
            . '{{main_water_valve_location}}. Close it now. Turn off any electrical appliances near the '
            . 'water — do not touch them if already wet.';

        $oldPowerTripping = $safetyLine . "\n\n" . 'Switch circuits back on one at a time, at the DB '
            . 'board, to find the tripping section — switching only, never opening the board. Unplug all '
            . 'appliances on that section, then try again.';
        $newPowerTripping = $safetyLine . "\n\n" . 'Your DB board is at: {{db_board_location}}. Switch '
            . 'all circuit breakers off, then on one at a time to find the section that trips. Unplug '
            . 'every appliance on that section and try again. If it still trips, leave it off and log '
            . 'the fault.';

        $updated = 0;
        $updated += DB::table('rental_fault_types')
            ->where('name', 'Burst pipe / water leak')
            ->where('is_default', true)
            ->where('first_aid_steps', $oldBurstPipe)
            ->update(['first_aid_steps' => $newBurstPipe, 'updated_at' => now()]);

        $updated += DB::table('rental_fault_types')
            ->where('name', 'Power tripping / no power')
            ->where('is_default', true)
            ->where('first_aid_steps', $oldPowerTripping)
            ->update(['first_aid_steps' => $newPowerTripping, 'updated_at' => now()]);

        \Illuminate\Support\Facades\Log::info('rental_fault_types backfill: burst pipe / power tripping wording', [
            'rows_updated' => $updated,
        ]);
    }

    public function down(): void
    {
        // Deliberately no-op — reversing would risk overwriting an agency's
        // OWN edit made after this migration ran (the row no longer matches
        // either "old" or "new" text deterministically). Not reversible
        // safely; matches this feature family's own established discipline
        // for one-way content backfills (Standard −1q).
    }
};
