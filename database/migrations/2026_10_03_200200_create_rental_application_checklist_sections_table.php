<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3.2 — the per-application SNAPSHOT of a checklist section, taken
 * once at application creation (RentalApplicationChecklistService::
 * snapshotFor()). Name/sort_order are copied at snapshot time so a later
 * rename/reorder on the template never rewrites an application already in
 * flight. `template_section_id` is kept only for provenance (nullable —
 * template row may since have been archived; never re-read for display).
 *
 * `description` is Johan's one free-text box PER SECTION (AT-430 §3.2,
 * verbatim: "each section has a desc where agents can type in what they
 * find") — a note on the application, never on the template.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Explicit short constraint names throughout — the auto-generated
        // {table}_{column}_foreign name overflows MySQL's 64-char identifier
        // limit on this table (same class of bug already hit once in
        // rental_inventory — see migration
        // 2026_09_22_...shorten_rental_inventory_composite_index_names).
        Schema::create('rental_application_checklist_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_application_id');
            $table->foreign('rental_application_id', 'racs_application_fk')
                ->references('id')->on('rental_applications')->cascadeOnDelete();
            $table->foreignId('template_section_id')->nullable();
            $table->foreign('template_section_id', 'racs_template_section_fk')
                ->references('id')->on('rental_checklist_template_sections')->nullOnDelete();
            $table->string('name', 191);
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->index(['rental_application_id', 'sort_order'], 'racs_application_sort_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_checklist_sections');
    }
};
