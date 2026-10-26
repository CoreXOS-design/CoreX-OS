<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * esign-signing-modes.md §1 (Johan, 26 Oct 2026): "Adopt once, click to stamp" and "Adopt once and fill everything" become ONE
 * choice, "Adopt once" - the signer clicks each place and/or presses "Apply to all my places". An agency (and an envelope
 * already sent) on either retired value is on the new one; the behaviour of a click is unchanged and "Apply to all" is added.
 * Data only - no column changes, so no schema snapshot change. Adoption rows already recorded keep the mode they were made
 * in (append-only evidence); code maps their value on read.
 */
return new class extends Migration
{
    private const LEGACY = ['adopt_then_stamp', 'adopt_and_fill_all'];

    public function up(): void
    {
        foreach (['docuperfect_esign_settings', 'signature_templates'] as $table) {
            DB::table($table)->whereIn('signing_mode', self::LEGACY)->update(['signing_mode' => 'adopt_once']);
        }
    }

    public function down(): void
    {
        // Not reversible: once merged there is no record of which retired value a row held, and both behave the same.
    }
};
