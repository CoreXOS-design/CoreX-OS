<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-452 round 2 (rentals-faults-work-orders.md §19.7-§19.9):
 *  - rental_fault_reports.follows_fault_report_id: a new report / repair request that follows an earlier one the tenant resolved themselves
 *    ("Have this repaired anyway", "Report it again"). The resolved report stays resolved; this is only the link.
 *  - rental_portal_settings.fault_recurrence_window_days: how long after a tenant-resolved report a new one of the same type counts as "reported
 *    before - the tenant resolved it then" (null reads as the model default, 90).
 *  - agency_service_provider_events: the contractor's own history (who, when, what, from which fault) - e.g. "Power tripping added by <agent>
 *    from fault #12". Append-only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_fault_reports') && ! Schema::hasColumn('rental_fault_reports', 'follows_fault_report_id')) {
            Schema::table('rental_fault_reports', function (Blueprint $table) {
                $table->unsignedBigInteger('follows_fault_report_id')->nullable()->index('rfr_follows_idx');
            });
        }
        if (Schema::hasTable('rental_portal_settings') && ! Schema::hasColumn('rental_portal_settings', 'fault_recurrence_window_days')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->unsignedSmallInteger('fault_recurrence_window_days')->nullable();
            });
        }
        if (! Schema::hasTable('agency_service_provider_events')) {
            Schema::create('agency_service_provider_events', function (Blueprint $table) {
                $table->id();
                $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
                $table->unsignedBigInteger('service_provider_id')->index('aspe_provider_idx');
                $table->string('event', 40);                                   // trade_added | created_from_fault
                $table->string('note', 500)->nullable();
                $table->unsignedBigInteger('rental_fault_report_id')->nullable();
                $table->unsignedBigInteger('rental_work_order_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_service_provider_events');
        if (Schema::hasTable('rental_portal_settings') && Schema::hasColumn('rental_portal_settings', 'fault_recurrence_window_days')) {
            Schema::table('rental_portal_settings', fn (Blueprint $t) => $t->dropColumn('fault_recurrence_window_days'));
        }
        if (Schema::hasTable('rental_fault_reports') && Schema::hasColumn('rental_fault_reports', 'follows_fault_report_id')) {
            Schema::table('rental_fault_reports', function (Blueprint $t) {
                $t->dropIndex('rfr_follows_idx');
                $t->dropColumn('follows_fault_report_id');
            });
        }
    }
};
