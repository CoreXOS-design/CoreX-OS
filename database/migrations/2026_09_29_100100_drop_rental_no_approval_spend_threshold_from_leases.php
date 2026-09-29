<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.4b — retired. Johan's 2026-09-26 ruling
 * put the no-approval spend-threshold override on the LEASE; asked again
 * directly 2026-09-29 while looking at the live lease screen, he ruled the
 * PROPERTY instead: "per property, populated to the leases screen." Two
 * editable overrides at once is exactly what he ruled against ("exactly ONE
 * place a human can change this number, and it is the property") — this
 * column is dropped rather than kept as a second, driftable mirror; the
 * lease screen reads through to properties.rental_no_approval_spend_threshold
 * (added in the companion migration) instead of storing its own value.
 *
 * Standard −1q (.ai/STANDARDS.md) — no backfill: this column has been live
 * one build cycle (Stage 3, 2026-09-26) on QA1 only, never consumed by any
 * gate, no real agency data behind it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('rental_no_approval_spend_threshold');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('rental_no_approval_spend_threshold', 10, 2)->nullable()->after('rental_amount');
        });
    }
};
