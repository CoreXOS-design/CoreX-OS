<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.2 — a single, immutable fact: this
 * item, seen by this person, during this inspection, at this time, in this
 * condition. Never edited, never deleted, not even soft-deleted — stricter
 * than the standard floor, deliberately: this is FICA/legal evidence (§0.11)
 * and even a soft-deleted row is still a row that could theoretically be
 * excluded from a query by mistake. No "delete" action exists anywhere for
 * this table's rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_inspection_item_id')->constrained()->cascadeOnDelete();

            $table->foreignId('observed_by_user_id')->nullable()
                ->constrained('users')->nullOnDelete();
            $table->foreignId('observed_by_contact_id')->nullable()
                ->constrained('contacts')->nullOnDelete();
            // exactly one of the two above is set — enforced at the application layer
            // (an observation is made by a user OR a self-reporting tenant contact).

            $table->string('condition', 20);
            // good | fair | damaged | not_working | missing | other
            $table->text('notes')->nullable();
            // required at the application layer when condition != 'good' — "no photos
            // equals lots of fights" (§0.3): a bad rating needs a reason on record.

            $table->string('source', 20);
            // in_inspection | tenant_fault_report | out_inspection | ad_hoc
            $table->boolean('reported_outside_window')->default(false);
            // only meaningful for source='tenant_fault_report'
            $table->string('window_decision', 10)->nullable();
            // accepted | rejected | deferred — agent's call per §0.1, only for a report
            // outside the fault window
            $table->text('window_decision_note')->nullable();
            $table->foreignId('window_decision_by_user_id')->nullable()
                ->constrained('users', indexName: 'ri_observations_window_decision_by_user_fk')->nullOnDelete();
            // explicit short constraint name — the auto-generated one exceeds MySQL's
            // 64-char identifier limit on this table's long name.
            $table->timestamp('window_decision_at')->nullable();

            $table->uuid('client_idempotency_key')->unique();
            // §7 — a retried sync after a dropped connection must never double-record
            // the same fact. Mirrors MobilePropertyController::uploadImages()'s existing
            // client_upload_id pattern.

            $table->timestamp('created_at');
            // the ONLY timestamp — no updated_at (nothing to update), no deleted_at.

            // explicit short names — auto-generated ones exceed MySQL's 64-char limit.
            $table->index(['agency_id', 'rental_inspection_id'], 'ri_observations_agency_inspection_idx');
            $table->index(['agency_id', 'rental_inspection_item_id'], 'ri_observations_agency_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_observations');
    }
};
