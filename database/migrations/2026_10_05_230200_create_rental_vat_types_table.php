<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency-maintained VAT types (Johan — "work like Pastel: VAT TYPE PER LINE
 * ITEM"). Seeded per agency with Standard (follows the agency's own
 * vat_rate, PerformanceSetting key, live), No VAT (fixed 0%), and Custom
 * (the agent types a rate on the line itself) — RentalVatType::
 * seedDefaultsFor(), fired on AgencyCreated same as every other per-agency
 * default list in this codebase (RentalApplicationHighlighter etc.). An
 * agency may add further fixed-rate types, rename, or archive any of them
 * (no hard delete — non-negotiable #1); exactly one is_default at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_vat_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();

            $table->string('name', 100);
            $table->string('rate_mode', 20);
            // agency_rate | fixed | custom_per_line
            //   agency_rate      — always follows PerformanceSetting('vat_rate') live, never stored here.
            //   fixed            — this row's own fixed_rate (e.g. "No VAT" = 0.00).
            //   custom_per_line  — no rate on the type at all; the agent types one on each line that uses it.
            $table->decimal('fixed_rate', 5, 2)->nullable();
            // only meaningful when rate_mode = fixed.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'is_active'], 'rvt_agency_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_vat_types');
    }
};
