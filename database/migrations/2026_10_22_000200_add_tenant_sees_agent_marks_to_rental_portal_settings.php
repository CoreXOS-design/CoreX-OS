<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rentals-faults-work-orders.md §18 (AT-452) — whether the tenant also sees the agent's marks and per-photo notes on their
 * own fault photos. Nullable: a null column reads as the model default (off).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_portal_settings') && ! Schema::hasColumn('rental_portal_settings', 'tenant_sees_agent_marks')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->boolean('tenant_sees_agent_marks')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_portal_settings') && Schema::hasColumn('rental_portal_settings', 'tenant_sees_agent_marks')) {
            Schema::table('rental_portal_settings', function (Blueprint $table) {
                $table->dropColumn('tenant_sees_agent_marks');
            });
        }
    }
};
