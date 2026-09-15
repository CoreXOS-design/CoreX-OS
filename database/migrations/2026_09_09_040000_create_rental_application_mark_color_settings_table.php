<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Highlighter freehand redesign, 2026-09-09 — Johan: "admin can pick 6
 * colours - agent 3 and auth 3." Deliberately follows the SAME safe
 * pattern as RentalApplicationQualifyingSetting: forAgency()/colorsFor()
 * never creates a row on read, only returns sensible in-memory defaults
 * until the agency explicitly saves. Existing marks need NO backfill —
 * they already store `category` (income/expense/unpaid) and are rendered
 * by looking up that category's colour at RENDER time, never by a colour
 * baked into the mark itself.
 *
 * Columns nullable — "never configured" (row doesn't exist, or a specific
 * column is null) resolves to the exact colours this feature already
 * shipped with tonight, so nothing on QA1 changes appearance until an
 * agency actually opens the settings screen and picks something else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_application_mark_color_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->unique('agency_id');

            $table->string('agent_income_color', 7)->nullable();
            $table->string('agent_expense_color', 7)->nullable();
            $table->string('agent_unpaid_color', 7)->nullable();
            $table->string('authoriser_income_color', 7)->nullable();
            $table->string('authoriser_expense_color', 7)->nullable();
            $table->string('authoriser_unpaid_color', 7)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_mark_color_settings');
    }
};
