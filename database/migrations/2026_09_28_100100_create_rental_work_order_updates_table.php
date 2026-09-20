<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4 — "the comprehensive log of what
 * damages were reported when and what was actioned," Johan's own words.
 * Append-only, never edited, never deleted — same evidence-integrity shape
 * as rental_inspection_observations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_work_order_updates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_work_order_id')
                ->constrained(indexName: 'rwo_updates_work_order_fk')->cascadeOnDelete();

            $table->string('update_type', 30);
            // status_change | note | supplier_assigned | supplier_changed
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->timestamp('created_at');
            // the ONLY timestamp — no updated_at, no deleted_at at all.

            $table->index(['agency_id', 'rental_work_order_id'], 'rwo_updates_agency_wo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_work_order_updates');
    }
};
