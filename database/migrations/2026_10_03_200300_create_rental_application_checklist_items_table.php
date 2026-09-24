<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3.2 — the per-application SNAPSHOT of a checklist item. Copied
 * from the template at application creation (name/help_text/note_required/
 * is_derived/derived_key), then lives its own life: state/note/who/when are
 * set as the agent works the file, independent of any later template edit.
 *
 * `template_item_id` is provenance only (nullable — the template row may
 * since be archived).
 *
 * Derived items (is_derived=true): state is COMPUTED, never written by a
 * user action — RentalApplicationChecklistService::syncDerivedStates()
 * updates state/note/set_at here so the stored row always mirrors the last
 * computed fact (readable directly by the settings/reporting side without
 * re-deriving), but no controller route accepts a manual state write for a
 * derived item. See that service for what each derived_key currently
 * computes, including the two keys with no real signal yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Column named `application_section_id` (not the fully-qualified
        // `rental_application_checklist_section_id`) plus explicit short
        // constraint/index names throughout — MySQL's 64-char identifier
        // limit, same class of bug already hit once in rental_inventory.
        Schema::create('rental_application_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('application_section_id');
            $table->foreign('application_section_id', 'raci_application_section_fk')
                ->references('id')->on('rental_application_checklist_sections')->cascadeOnDelete();
            $table->foreignId('template_item_id')->nullable()
                ->constrained('rental_checklist_template_items')->nullOnDelete();
            $table->string('name', 191);
            $table->text('help_text')->nullable();
            $table->boolean('note_required')->default(false);
            $table->boolean('is_derived')->default(false);
            $table->string('derived_key', 60)->nullable();
            $table->string('state', 20)->default('not_started');
            // not_started | done | not_applicable
            $table->text('note')->nullable();
            $table->foreignId('set_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('set_at')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['application_section_id', 'sort_order'], 'raci_section_sort_idx');
            $table->index(['agency_id', 'derived_key'], 'raci_agency_derived_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_application_checklist_items');
    }
};
