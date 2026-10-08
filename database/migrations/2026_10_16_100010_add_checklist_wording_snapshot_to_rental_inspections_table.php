<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §49 — a sent (or signed) report keeps the checklist item wording it was sent with.
 * `checklist_wording_snapshot` is a map {item id => the item's label at that moment}, taken when a party first signs
 * and again (if still empty) when the report is completed; cleared when "Edit report" reopens a signed report. Nullable:
 * an inspection that predates this column simply has no snapshot and reads from the live checklist exactly as before
 * (no data is repaired — see the spec for the count on QA1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->json('checklist_wording_snapshot')->nullable();
            $table->timestamp('checklist_wording_snapshot_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('rental_inspections', function (Blueprint $table) {
            $table->dropColumn(['checklist_wording_snapshot', 'checklist_wording_snapshot_at']);
        });
    }
};
