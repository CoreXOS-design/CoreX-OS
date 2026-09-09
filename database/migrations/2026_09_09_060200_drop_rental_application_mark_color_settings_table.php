<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Highlighter collection expansion, 2026-09-09 — this table (the fixed
 * three-for-agent/three-for-authoriser colour settings, shipped a few hours
 * before this migration) is fully superseded by rental_application_highlighters.
 * Its data was read forward into the new table by the previous migration
 * (2026_09_09_060100) BEFORE this one runs — nothing here is lost, this is
 * cleanup of a table whose only purpose was already carried forward.
 * Deliberately dropped rather than left dead in the schema: two live
 * "configure highlighter colours" tables would be exactly the kind of
 * quiet drift CoreX's build standard exists to prevent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('rental_application_mark_color_settings');
    }

    public function down(): void
    {
        Schema::create('rental_application_mark_color_settings', function (\Illuminate\Database\Schema\Blueprint $table) {
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
};
