<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-takeon-import.md §11 (Landing 2). column_mapping_id is
 * set only when the admin chose an EXISTING saved mapping (nullable — a
 * one-off manual mapping, or the Landing 1 exact-template fast path,
 * never saves/references one). column_mapping_json is the actual
 * field_key => uploaded-file column index this run used, snapshotted at
 * confirm time for audit regardless of whether it came from a saved
 * mapping, a one-off manual map, or the exact-template fast path.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_take_on_import_runs', function (Blueprint $table) {
            $table->foreignId('column_mapping_id')->nullable()->after('source_file_path')
                ->constrained('rental_take_on_column_mappings')->nullOnDelete();
            $table->json('column_mapping_json')->nullable()->after('column_mapping_id');
        });
    }

    public function down(): void
    {
        Schema::table('rental_take_on_import_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('column_mapping_id');
            $table->dropColumn('column_mapping_json');
        });
    }
};
