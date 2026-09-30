<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §13.3 — the resolved routing
 * decision's route and spend limit, persisted so a work order later raised
 * from this fault report can gate owner approval against the ROUTE's own
 * limit instead of the property's flat threshold, mirroring the existing
 * quote-gate mechanic exactly (RentalWorkOrder::selectQuote(), §3.4c of
 * rental-work-orders.md) rather than inventing a second gate shape.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->string('routed_via', 20)->nullable()->after('rental_fault_type_id');
            $table->decimal('routed_spend_limit', 10, 2)->nullable()->after('routed_via');
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropColumn(['routed_via', 'routed_spend_limit']);
        });
    }
};
