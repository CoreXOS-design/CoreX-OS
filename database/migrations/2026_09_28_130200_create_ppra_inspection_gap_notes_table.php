<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PPRA Inspection Pack Phase A — .ai/specs/ppra-inspection-pack.md §4.4
 *
 * Tracks a remediation date + note against an open (amber/red) checklist
 * item (a..m). "Current" note for an item = latest non-deleted,
 * non-resolved row for that agency + slug. Versioned like
 * agency_transformation_notes: editing creates a new row rather than
 * mutating one in place, so remediation history stays auditable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ppra_inspection_gap_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->string('checklist_item_slug', 10); // 'a'..'m'
            $table->date('remediation_due_date')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('assigned_to_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            $table->index(['agency_id', 'checklist_item_slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ppra_inspection_gap_notes');
    }
};
