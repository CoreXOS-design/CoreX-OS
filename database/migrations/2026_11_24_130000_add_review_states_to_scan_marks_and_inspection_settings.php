<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-472 / .ai/specs/rental-inspection-form.md §13.15 - Johan, 20 Oct 2026, review of scan #25.
 *
 * rental_inspection_scan_marks
 *   tick_boxes  - how many of the row's boxes read as marked (0 = nothing picked up, 1 = a tick, 2+ = two boxes). Splits "Not picked up at all"
 *                 from "Picked up but unsure"; both used to be the single `ambiguous` flag. Null on marks read before this column existed.
 *   note_ink    - the note area of the row had handwriting in it when the scan was read (so an empty or unusable AI answer is a note to type in,
 *                 not an empty row). Only set while the AI note read is on.
 * rental_inspection_settings (agency-configurable, read-time defaults - no column default, like omr_mark_threshold)
 *   omr_unsure_below        - a tick whose confidence (0-1) is under this is "picked up but unsure": the agent must confirm it. Default 0.50
 *                             (the value that was a constant in the reader and the review screen).
 *   note_read_min_confidence - an AI-read note whose confidence is under this is not filled in: the agent is told to type it. Default 0.50.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_scan_marks', function (Blueprint $table) {
            $table->unsignedTinyInteger('tick_boxes')->nullable()->after('ambiguous');
            $table->boolean('note_ink')->default(false)->after('detected_note_confidence');
        });
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->decimal('omr_unsure_below', 3, 2)->nullable()->after('omr_mark_threshold');
            $table->decimal('note_read_min_confidence', 3, 2)->nullable()->after('omr_unsure_below');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_settings', function (Blueprint $table) {
            $table->dropColumn(['omr_unsure_below', 'note_read_min_confidence']);
        });
        Schema::table('rental_inspection_scan_marks', function (Blueprint $table) {
            $table->dropColumn(['tick_boxes', 'note_ink']);
        });
    }
};
