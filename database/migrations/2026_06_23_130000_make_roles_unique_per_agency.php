<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles become agency-scoped (.ai/specs/roles-permissions.md).
 *
 * The global UNIQUE(name) blocked every agency from owning its own copy of a
 * role named "admin"/"agent"/etc. Replace it with UNIQUE(name, agency_id) so
 * each agency's role set is unique within that agency, while owner-role and
 * template rows (agency_id IS NULL) stay singular.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            // Default Laravel index name for $table->string('name')->unique()
            $table->dropUnique('roles_name_unique');
            $table->unique(['name', 'agency_id'], 'roles_name_agency_unique');
            $table->index(['agency_id', 'sort_order'], 'roles_agency_sort_index');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropUnique('roles_name_agency_unique');
            $table->dropIndex('roles_agency_sort_index');
            $table->unique('name', 'roles_name_unique');
        });
    }
};
