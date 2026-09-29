<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, property 5792: 46 fields whose placeholder read "Notes
 * (required)" were empty, and the inspection still reached
 * awaiting_signature — the required-note vocabulary
 * (RentalInspectionSetting::conditionStatesFor()'s own `requires_notes`
 * per key, built 2026-09-21) was never actually enforced anywhere; the
 * placeholder text was the only thing that ever claimed it.
 *
 * WHICH conditions require a note is already agency-configurable
 * (`condition_states` JSON, added 2026_09_21_160000) — no new column
 * needed for that. What's missing is whether the requirement BLOCKS
 * progression to awaiting_signature/completed, or only WARNS — Johan's
 * own instruction: "must itself be an agency setting, defaulting to
 * block." Nullable, same read-time-default pattern as every other
 * resolver on this table (null = the built-in default, `true`/block).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->boolean('require_notes_blocks_progression')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('require_notes_blocks_progression');
        });
    }
};
