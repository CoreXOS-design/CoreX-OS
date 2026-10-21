<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md "reOS Tenancy Schedule import" (AT-479) R1/R3.
 *
 * Runs get an import type (`source`) — every existing run is a `corex_template` run, which is the column default.
 * Rows get what the reOS review screen needs to remember: the reOS lease id/link, the lease already in CoreX for it,
 * whether the user ticked the row, the user's per-row choices, the computed blockers, why the last attempt failed,
 * and whether the import created or updated the lease. All nullable / defaulted, so existing rows are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_take_on_import_runs', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_take_on_import_runs', 'source')) {
                $table->string('source', 30)->default('corex_template')->after('status');
            }
            if (! Schema::hasColumn('rental_take_on_import_runs', 'rent_roll_filename')) {
                $table->string('rent_roll_filename', 255)->nullable()->after('source_file_path');
            }
        });

        Schema::table('rental_take_on_import_rows', function (Blueprint $table) {
            if (! Schema::hasColumn('rental_take_on_import_rows', 'external_id')) {
                $table->string('external_id', 64)->nullable()->after('row_number');
                $table->string('external_url', 500)->nullable()->after('external_id');
                $table->unsignedBigInteger('existing_lease_id')->nullable()->after('external_url');
                $table->boolean('selected')->default(false)->after('existing_lease_id');
                $table->json('decisions_json')->nullable()->after('selected');
                $table->json('blockers_json')->nullable()->after('decisions_json');
                $table->boolean('is_blocked')->default(false)->after('blockers_json');
                $table->text('import_error')->nullable()->after('is_blocked');
                $table->string('result_action', 10)->nullable()->after('import_error');
                $table->index(['run_id', 'external_id'], 'rtoi_rows_run_external_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rental_take_on_import_rows', function (Blueprint $table) {
            if (Schema::hasColumn('rental_take_on_import_rows', 'external_id')) {
                $table->dropIndex('rtoi_rows_run_external_idx');
                $table->dropColumn(['external_id', 'external_url', 'existing_lease_id', 'selected', 'decisions_json', 'blockers_json', 'is_blocked', 'import_error', 'result_action']);
            }
        });

        Schema::table('rental_take_on_import_runs', function (Blueprint $table) {
            $drop = array_values(array_filter(['source', 'rent_roll_filename'], fn ($c) => Schema::hasColumn('rental_take_on_import_runs', $c)));
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
