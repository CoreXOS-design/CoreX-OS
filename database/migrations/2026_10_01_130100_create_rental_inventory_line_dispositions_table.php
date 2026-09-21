<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inventory.md §8 — the move-out finding for one inventory
 * line. APPEND-ONLY, never edited or deleted: this is cc5's Option B,
 * approved by Johan — "it matches the supersede-never-edit discipline
 * across the whole module and it means a move-out record survives a later
 * dispute." One row per recording event; RentalInventoryLine::
 * latestDisposition() reads the most recent row as "current," the earlier
 * rows stay exactly as filed. Mirrors how RentalInspectionObservation is a
 * distinct, comparable event against a persistent RentalInspectionItem —
 * never rewritten in place.
 *
 * `quantity_found` is NULLABLE and never coerced to 0 (Johan, verbatim:
 * "A tenant must never be charged for something nobody counted") — the
 * exact discipline rental-inspections.md §17's header block already
 * established for keys_count/remotes_count, carried here deliberately.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inventory_line_dispositions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            // Column only here — the constraint below is declared explicitly
            // with a short name, since the auto-generated one (67 chars)
            // exceeds MySQL's 64-char identifier limit.
            $table->unsignedBigInteger('rental_inventory_line_id');
            // Denormalized from the line, for scoping/query convenience —
            // same convention already used elsewhere in this feature family.
            $table->foreignId('rental_inventory_id')->constrained('rental_inventories')->cascadeOnDelete();

            $table->string('disposition_key', 60);
            $table->unsignedInteger('quantity_found')->nullable();
            $table->text('notes')->nullable();

            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('recorded_at');

            $table->timestamps();

            // Explicit short names — the auto-generated ones (75 and 70
            // chars) both exceed MySQL's 64-char identifier limit. Same
            // class of defect cc1 already found and fixed once on the
            // capture half's migrations (0624e864b) — caught here before
            // landing instead of needing a second fix-up commit.
            $table->index(['agency_id', 'rental_inventory_line_id'], 'rental_inv_line_dispositions_agency_line_idx');
            $table->index(['agency_id', 'rental_inventory_id'], 'rental_inv_line_dispositions_agency_inv_idx');

            $table->foreign('rental_inventory_line_id', 'rental_inv_line_disp_line_id_foreign')
                ->references('id')->on('rental_inventory_lines')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inventory_line_dispositions');
    }
};
