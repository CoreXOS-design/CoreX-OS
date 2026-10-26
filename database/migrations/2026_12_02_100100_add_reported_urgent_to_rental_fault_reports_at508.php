<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-508 (P4) - a tenant on the mobile app can mark the fault they are reporting as URGENT. It is treated like an emergency-type fault
 * (rentals-faults-work-orders.md 6.5): flagged on the agent's list and alerted at the elevated severity. It does NOT change the fault's type,
 * who is asked to approve, or any spend rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->boolean('reported_urgent')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('rental_fault_reports', function (Blueprint $table) {
            $table->dropColumn('reported_urgent');
        });
    }
};
