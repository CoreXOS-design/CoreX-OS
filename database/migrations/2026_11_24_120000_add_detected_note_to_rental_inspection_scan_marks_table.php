<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-472 / .ai/specs/rental-inspection-form.md §13.14 - Johan, 20 Oct 2026: the handwritten note on a scanned site form is read by AI and
 * pre-fills the note box on the review screen. `detected_note` is what the read step found for that row (null = nothing legible / not read);
 * `detected_note_confidence` runs 0-1 like `detected_confidence`. Neither is ever a recorded note: the agent confirms or edits it, and only
 * what they confirm reaches the inspection (as an ordinary observation's notes).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspection_scan_marks', function (Blueprint $table) {
            $table->text('detected_note')->nullable()->after('ambiguous');
            $table->decimal('detected_note_confidence', 3, 2)->nullable()->after('detected_note');
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspection_scan_marks', function (Blueprint $table) {
            $table->dropColumn(['detected_note', 'detected_note_confidence']);
        });
    }
};
