<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Johan, 2026-09-21, from Retha's real paper out-inspection form: a single
 * free-text summary block for the whole inspection, at the foot — hers
 * reads "OVERALL - APARTMENT CLEAN - FAIR - PARTIALLY FURNISHED". A plain
 * mutable column on the inspection itself (like cancel_reason), not an
 * append-only history like rental_inspection_observations/room_notes —
 * this is the inspection's own summary field, not an evidentiary per-event
 * fact, and RentalInspection is already a mutable lifecycle record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->text('overall_notes')->nullable()->after('cancel_reason');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn('overall_notes');
        });
    }
};
