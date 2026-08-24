<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The 2026-08-18 wizard commit (773badc54) inserted 'welcome', 'proforma', and
 * 'market_intelligence' into AgencyOnboardingSetup::STEPS, shifting every later
 * step's array position. `current_step` is a raw persisted 1-based index into
 * that array — any row still in progress (completed_at IS NULL) when this
 * shipped now resumes at the WRONG step key.
 *
 * Remap using the OLD (13-step) -> NEW (16-step) position table for the same
 * step key. Completed rows are left untouched (their step position is frozen
 * history, not a resume point). Rows already created under the NEW order
 * (current_step > 13) are left untouched — they were never stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        // old 1-based position => new 1-based position, keyed by shared step key.
        $remap = [
            1  => 2,  // identity
            2  => 3,  // capabilities
            3  => 4,  // branding
            4  => 5,  // branches
            5  => 6,  // commission
            6  => 8,  // properties
            7  => 9,  // presentations
            8  => 10, // matches
            9  => 12, // contacts
            10 => 13, // compliance
            11 => 14, // notifications
            12 => 15, // roles
            13 => 16, // access
        ];

        $this->applyRemap($remap);
    }

    public function down(): void
    {
        $remap = [
            2  => 1,
            3  => 2,
            4  => 3,
            5  => 4,
            6  => 5,
            8  => 6,
            9  => 7,
            10 => 8,
            12 => 9,
            13 => 10,
            14 => 11,
            15 => 12,
            16 => 13,
        ];

        $this->applyRemap($remap);
    }

    /**
     * Snapshot every affected row's id + current step BEFORE writing anything,
     * then apply by id. Chaining per-value UPDATE...WHERE current_step=X
     * statements directly against the live table is unsafe: an earlier step in
     * the map can write a "new" value that collides with a later step's "old"
     * key (e.g. 1=>2 then 2=>3), causing that row to be remapped twice.
     */
    private function applyRemap(array $remap): void
    {
        $rows = DB::table('agency_onboarding_setups')
            ->whereNull('completed_at')
            ->whereIn('current_step', array_keys($remap))
            ->get(['id', 'current_step']);

        foreach ($rows as $row) {
            DB::table('agency_onboarding_setups')
                ->where('id', $row->id)
                ->update(['current_step' => $remap[$row->current_step]]);
        }
    }
};
