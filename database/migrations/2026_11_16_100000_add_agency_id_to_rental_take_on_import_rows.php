<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Release audit 2026-10-18 (A-M2): take-on import rows had no agency_id, so a
 * row bound by id from another agency could be read/written. The row now
 * carries its own agency_id (BelongsToAgency), stamped from its parent run.
 * Nullable on purpose: existing databases are backfilled below, and a NULL is
 * an orphan that AgencyScope hides rather than shares.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('rental_take_on_import_rows', 'agency_id')) {
            Schema::table('rental_take_on_import_rows', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
                $table->index('agency_id', 'rental_take_on_import_rows_agency_id_index');
            });
        }

        DB::table('rental_take_on_import_rows')
            ->whereNull('agency_id')
            ->update([
                'agency_id' => DB::raw('(SELECT r.agency_id FROM rental_take_on_import_runs r WHERE r.id = rental_take_on_import_rows.run_id)'),
            ]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('rental_take_on_import_rows', 'agency_id')) {
            Schema::table('rental_take_on_import_rows', function (Blueprint $table) {
                $table->dropIndex('rental_take_on_import_rows_agency_id_index');
                $table->dropColumn('agency_id');
            });
        }
    }
};
