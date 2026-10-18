<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/leases.md §17 — every lease carries two agents of its own: the OWNER'S agent (landlord side) and the
 * TENANT'S agent. They may be the same person. Johan, 8 Oct 2026: "retha is the listing agent, maggie is the tenant
 * agent… I would still on lease show the agents as such and it can be changed if need be."
 *
 * Both are nullable and null-on-delete (a user who is removed leaves the lease with no agent on that side rather than
 * blocking the delete; the portal then falls back to the property's agent, then the branch). Columns only — no data is
 * written here. Existing leases are filled by `php artisan leases:backfill-agents` (dry-run first, reversible, logged),
 * never silently by a deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (! Schema::hasColumn('leases', 'owner_agent_user_id')) {
                $table->unsignedBigInteger('owner_agent_user_id')->nullable()->after('created_by_user_id');
                $table->foreign('owner_agent_user_id', 'leases_owner_agent_user_id_foreign')->references('id')->on('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('leases', 'tenant_agent_user_id')) {
                $table->unsignedBigInteger('tenant_agent_user_id')->nullable()->after('owner_agent_user_id');
                $table->foreign('tenant_agent_user_id', 'leases_tenant_agent_user_id_foreign')->references('id')->on('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('leases', function (Blueprint $table) {
            if (Schema::hasColumn('leases', 'tenant_agent_user_id')) {
                $table->dropForeign('leases_tenant_agent_user_id_foreign');
                $table->dropColumn('tenant_agent_user_id');
            }
            if (Schema::hasColumn('leases', 'owner_agent_user_id')) {
                $table->dropForeign('leases_owner_agent_user_id_foreign');
                $table->dropColumn('owner_agent_user_id');
            }
        });
    }
};
