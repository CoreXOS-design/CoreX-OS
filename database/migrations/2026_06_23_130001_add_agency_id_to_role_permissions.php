<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permission grants become agency-scoped (.ai/specs/roles-permissions.md).
 *
 * Previously role_permissions was keyed by (role, permission_key) with no
 * agency_id — so one agency editing its "admin" rewrote every agency's "admin".
 * Add agency_id and widen the unique key so each agency's grants are isolated.
 *
 * Existing rows keep agency_id = NULL and are retained as seed TEMPLATES; the
 * 130002 backfill clones them into each real agency. NULL rows are only ever
 * resolved for a user with no agency (owners bypass checks anyway).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->foreignId('agency_id')->nullable()->after('permission_key')
                ->constrained()->nullOnDelete();

            // Default Laravel index name for ->unique(['role', 'permission_key'])
            $table->dropUnique('role_permissions_role_permission_key_unique');
            $table->unique(['role', 'permission_key', 'agency_id'], 'role_perms_role_key_agency_unique');
            $table->index(['agency_id', 'role'], 'role_perms_agency_role_index');
        });
    }

    public function down(): void
    {
        Schema::table('role_permissions', function (Blueprint $table) {
            $table->dropUnique('role_perms_role_key_agency_unique');
            $table->dropIndex('role_perms_agency_role_index');
            $table->dropConstrainedForeignId('agency_id');
            $table->unique(['role', 'permission_key'], 'role_permissions_role_permission_key_unique');
        });
    }
};
