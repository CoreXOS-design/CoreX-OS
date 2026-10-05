<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pastel-style enhancement, 2026-10-05 — the old free-text `type`/`unit`
 * columns are fully superseded by rental_catalogue_item_type_id/
 * rental_catalogue_unit_id (backfilled by 2026_10_05_240300 immediately
 * before this one runs). No shortcut dead columns left behind.
 *
 * Staged in three separate Schema::table() calls, not one: MySQL requires
 * SOME index with `agency_id` as its leftmost column to exist at all times
 * to satisfy the `agency_id` foreign key — the old composite index
 * (agency_id, type, is_active) was that index, so dropping it in the same
 * statement as adding its replacement throws "Cannot drop index ... needed
 * in a foreign key constraint" (caught live running this migration). The
 * replacement index is added FIRST under a temporary name, the old index
 * and columns are dropped second, then the temporary index is renamed to
 * the name the old one held — at every stage, an agency_id-leading index
 * exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->index(['agency_id', 'rental_catalogue_item_type_id', 'is_active'], 'rci_agency_type2_active_idx');
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->dropIndex('rci_agency_type_active_idx');
            $table->dropColumn(['type', 'unit']);
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->renameIndex('rci_agency_type2_active_idx', 'rci_agency_type_active_idx');
        });
    }

    public function down(): void
    {
        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->string('type', 10)->nullable()->after('rental_catalogue_item_type_id');
            $table->string('unit', 30)->nullable()->after('rental_catalogue_unit_id');
            $table->index(['agency_id', 'type', 'is_active'], 'rci_agency_type2_active_idx');
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->dropIndex('rci_agency_type_active_idx');
        });

        Schema::table('rental_catalogue_items', function (Blueprint $table) {
            $table->renameIndex('rci_agency_type2_active_idx', 'rci_agency_type_active_idx');
        });
    }
};
