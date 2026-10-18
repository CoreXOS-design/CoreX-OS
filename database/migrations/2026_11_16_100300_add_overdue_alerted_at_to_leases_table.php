<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Release audit 2026-10-18, C-F6 - the "lease is overdue" alert (signatures:check-lease-expiry) used a 7-day cache key, so an
 * agent whose lease stayed active past its end date was told again every 7 days for ever (and again after every cache clear).
 *
 * leases.overdue_alerted_at is the durable per-lease marker: set when the overdue alert is sent, cleared by the same command
 * once the lease is no longer overdue (renewed / new end date / ended). One alert per lease per overdue episode.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('leases', 'overdue_alerted_at')) {
            return;
        }

        Schema::table('leases', function (Blueprint $table) {
            $table->timestamp('overdue_alerted_at')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('leases', 'overdue_alerted_at')) {
            Schema::table('leases', function (Blueprint $table) {
                $table->dropColumn('overdue_alerted_at');
            });
        }
    }
};
