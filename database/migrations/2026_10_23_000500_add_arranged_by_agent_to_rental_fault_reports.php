<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 round 2, item I (rentals-faults-work-orders.md §19.10) - a fault the AGENT arranged without waiting for the owner (an emergency, or a small
 * job inside the property's no-approval limit). The owner is INFORMED, not asked: these columns say when, by whom, on what basis and under which limit,
 * so the owner portal can say "Your agent arranged this repair under your R<limit> no-approval limit" (or "as an emergency").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_fault_reports') && ! Schema::hasColumn('rental_fault_reports', 'arranged_by_agent_at')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->timestamp('arranged_by_agent_at')->nullable();
                $table->unsignedBigInteger('arranged_by_user_id')->nullable();
                $table->string('arranged_basis', 20)->nullable();            // no_approval_limit | emergency
                $table->decimal('arranged_limit_amount', 12, 2)->nullable(); // the property's no-approval limit at that moment
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_fault_reports') && Schema::hasColumn('rental_fault_reports', 'arranged_by_agent_at')) {
            Schema::table('rental_fault_reports', fn (Blueprint $t) => $t->dropColumn(['arranged_by_agent_at', 'arranged_by_user_id', 'arranged_basis', 'arranged_limit_amount']));
        }
    }
};
