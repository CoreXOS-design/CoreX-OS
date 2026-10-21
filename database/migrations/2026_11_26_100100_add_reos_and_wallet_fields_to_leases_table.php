<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md "reOS Tenancy Schedule import" (AT-479) R7.
 *
 * Where a lease came from in another system (external_source + external_id + external_url — the reOS lease id and
 * link; a re-import finds the lease by agency + source + id instead of creating it again), whether it rolls over
 * (null = not known), and the payment references the tenant / owner use to pay through reOS. All nullable and
 * editable on the lease; nothing here is computed. The unique key lets MySQL hold many NULL pairs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (! Schema::hasColumn('leases', 'external_id')) {
                $table->string('external_source', 30)->nullable()->after('migrated_from_id');
                $table->string('external_id', 64)->nullable()->after('external_source');
                $table->string('external_url', 500)->nullable()->after('external_id');
                $table->boolean('rollover')->nullable()->after('is_month_to_month');
                $table->string('tenant_wallet_ref', 100)->nullable()->after('external_url');
                $table->string('owner_wallet_ref', 100)->nullable()->after('tenant_wallet_ref');
                $table->unique(['agency_id', 'external_source', 'external_id'], 'leases_agency_external_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (Schema::hasColumn('leases', 'external_id')) {
                $table->dropUnique('leases_agency_external_unique');
                $table->dropColumn(['external_source', 'external_id', 'external_url', 'rollover', 'tenant_wallet_ref', 'owner_wallet_ref']);
            }
        });
    }
};
