<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3.2/§3.3 — a line inside a checklist section. `note_required`
 * gates whether the per-application item can be marked done without a note
 * (§3.3: "TPN outcome recorded" ships note-required by default).
 *
 * `is_derived` + `derived_key`, Johan's ruling (AT-430 §3.4/Part D): the
 * three Lease-progress items whose facts the lease already carries (deposit
 * paid, first rent paid, occupied) are marked derived here so an agency
 * editing its own checklist cannot turn a derived item into a manual one —
 * the settings screen must render these read-only-derived, never offer a
 * checkbox to flip them. See RentalApplicationChecklistService for what each
 * derived_key currently resolves to, including the two keys that have no
 * real signal to derive from yet (flagged there, not invented here).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Column named `template_section_id` (not the fully-qualified
        // `rental_checklist_template_section_id`) — MySQL's 64-char
        // identifier limit on the auto-named FK constraint, same class of
        // bug already hit once in rental_inventory (see migration
        // 2026_09_15_...shorten_rental_inventory_composite_index_names).
        Schema::create('rental_checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('template_section_id')
                ->constrained('rental_checklist_template_sections')->cascadeOnDelete();
            $table->string('name', 191);
            $table->text('help_text')->nullable();
            $table->boolean('note_required')->default(false);
            $table->boolean('is_derived')->default(false);
            $table->string('derived_key', 60)->nullable();
            // lease_deposit_paid | lease_first_rent_paid | lease_occupied
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['template_section_id', 'sort_order'], 'rcti_section_sort_idx');
            $table->index(['agency_id', 'derived_key'], 'rcti_agency_derived_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_checklist_template_items');
    }
};
