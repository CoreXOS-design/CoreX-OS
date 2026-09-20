<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-work-orders.md §3.4b, settled 2026-09-26 — Johan's own
 * ruling, asked directly: the spend-threshold override belongs on the
 * LEASE, not the property this spec had previously recommended. Nullable —
 * null means "use the agency default" (rental_work_order_settings.
 * no_approval_spend_threshold), matching this spec's own null-means-inherit
 * pattern used everywhere else. A new lease (renewal or new tenant) starts
 * with no override — nothing carries forward from a previous, unrelated
 * tenancy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->decimal('rental_no_approval_spend_threshold', 10, 2)->nullable()->after('rental_amount');
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            $table->dropColumn('rental_no_approval_spend_threshold');
        });
    }
};
