<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §3.5 — the 7-day windows, agency-
 * configurable, never hardcoded (§0.12). Same established pattern as
 * RentalApplicationQualifyingSetting: one agency-scoped row, nullable
 * columns, a DEFAULT_* constant read when the row/column is absent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete()->unique();

            $table->unsignedSmallInteger('fault_report_window_days')->nullable();
            $table->unsignedSmallInteger('out_inspection_signing_window_days')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_settings');
    }
};
