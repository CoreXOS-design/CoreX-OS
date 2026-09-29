<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §2.2 — a fault report may
 * reference the catalogue entry the reporter picked. Nullable: an
 * agent-captured report can still be free-text-only if nothing in the
 * catalogue fits. No FK constraint on delete (nullOnDelete) — the
 * reference survives even after the fault type is archived (soft-delete
 * only, so the row never actually vanishes, but this is defensive).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->foreignId('rental_fault_type_id')->nullable()
                ->after('rental_inspection_item_id')
                ->constrained('rental_fault_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('rental_fault_type_id');
        });
    }
};
