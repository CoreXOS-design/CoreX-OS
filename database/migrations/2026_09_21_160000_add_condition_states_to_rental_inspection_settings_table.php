<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-21, from Retha's real paper out-inspection form: "Missing"
 * means "should be here and isn't" (a deposit argument); N/A means "was
 * never here" (not an argument at all) — two different meanings that the
 * app could only say one of. Adds N/A alongside the existing six condition
 * states, and makes the whole SET agency-configurable — Retha's own paper
 * form uses Good / OK / Bad, ours uses Good / Fair / Damaged / Not working
 * / Missing / Other; neither is forced on the other agency.
 *
 * JSON array of {key, label, requires_notes} — requires_notes generalizes
 * "does picking this condition need a reason on record" (§0.3) beyond the
 * old hardcoded "anything but Good" rule, so a reduced/renamed custom set
 * still expresses the rule correctly. Null resolves to
 * RentalInspectionSetting::DEFAULT_CONDITION_STATES.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->json('condition_states')->nullable()->after('room_type_walking_order');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn('condition_states');
        });
    }
};
