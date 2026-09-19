<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-422 — an imported listing becomes a normal, new-looking listing the moment a
 * user changes its status, expiry date or listed date. `p24_imported_at` stays as
 * the permanent record of WHEN it was imported (and keeps the importer's "already
 * stamped, don't restamp" rule and the backfill command's "only stamp nulls" rule
 * working); this column records THAT a user has since taken it over. Imported Stock,
 * the "Imported" tag and the Properties search ordering all treat a row with this
 * set as an ordinary property.
 *
 * Nullable, no default: every existing row is "not released", i.e. unchanged
 * behaviour. Guarded so a re-run after a partial failure is a no-op.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('properties') || Schema::hasColumn('properties', 'imported_released_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('imported_released_at')->nullable()->after('p24_imported_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('properties', 'imported_released_at')) {
            return;
        }

        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('imported_released_at');
        });
    }
};
