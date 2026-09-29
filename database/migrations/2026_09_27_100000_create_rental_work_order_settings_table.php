<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.1/§3.4b/§8 — one row per agency. Full
 * schema built now (Stage 3) even though `completion_requires_photo` and
 * `overdue_reminder_days` are Stage 4 concerns with no consumer yet — same
 * "spec-complete from day one" discipline Stage 1 applied to
 * rental_fault_reports, avoiding a second ALTER TABLE when Stage 4 lands.
 * Only `no_approval_spend_threshold` is actually read anywhere yet
 * (RentalWorkOrderSetting::thresholdFor()) — it has no gate consuming it
 * either, since no fault report or work order carries a cost figure to
 * compare it against until Stage 4's cost_amount exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_work_order_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete()->unique();

            $table->boolean('completion_requires_photo')->default(true);
            $table->unsignedSmallInteger('overdue_reminder_days')->nullable();
            $table->decimal('no_approval_spend_threshold', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_work_order_settings');
    }
};
