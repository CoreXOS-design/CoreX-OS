<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.2, amended 2026-09-17 (leases.md §9 —
 * amendment applied on cc5-rental-inspections-lease-amendment @ 3b1e57bb7):
 * `lease_id` is REQUIRED. This is the authoritative answer to "which
 * tenancy did this inspection event belong to" — the entire reason leases
 * were built before this table. `property_id` is denormalized-only, always
 * set to lease.property_id at creation, never edited independently — exists
 * purely so "every inspection on this property" queries don't require a
 * join through leases. It is never the authoritative tenancy answer.
 *
 * Runs AFTER the leases migrations (2026_09_17_0900-0300) by filename sort
 * — required FK dependency, per the spec's own migration-order note (§12).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lease_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();
            // denormalized — see docblock above.

            $table->string('type', 10);
            // in | out | ad_hoc
            $table->string('status', 20)->default('draft');
            // draft | in_progress | awaiting_signature | completed | cancelled

            $table->date('scheduled_for')->nullable();
            $table->timestamp('fault_report_deadline_at')->nullable();
            // set at creation for type='in': created_at + agency's fault_report_window_days
            $table->timestamp('signing_deadline_at')->nullable();
            // set when status moves to awaiting_signature: + agency's signing window
            $table->timestamp('completed_at')->nullable();

            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();

            $table->foreignId('created_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();
            // Gated at the application layer, §3.3: deletable only while it has ZERO
            // observations recorded against it. Once one exists, only 'cancelled' — the
            // full-CRUD floor's archive/restore path applies to the empty-draft case only.

            $table->index(['agency_id', 'lease_id', 'type']);
            $table->index(['agency_id', 'property_id']);
            $table->index(['agency_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspections');
    }
};
