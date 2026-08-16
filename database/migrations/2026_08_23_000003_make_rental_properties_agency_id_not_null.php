<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `rental_properties.agency_id` NOT NULL, per the spec's pillar-table
 * pattern (see .ai/specs/multi-tenancy.md, presentations precedent).
 *
 * Guarded: refuses to advance if any row is still NULL (created_by orphan,
 * created_by pointing at an agency-less user/System Owner, or a row
 * inserted between the previous migration and this one). If this throws,
 * do NOT force the column NOT NULL by hand — investigate the orphan rows
 * (they are invisible to every tenant under AgencyScope, not "shared") and
 * either fix their created_by or assign an agency_id manually before
 * re-running this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $stillNull = DB::table('rental_properties')->whereNull('agency_id')->count();
        if ($stillNull > 0) {
            throw new \RuntimeException(
                "rental_properties still has {$stillNull} row(s) with NULL agency_id. "
                . 'Investigate created_by orphans (or re-run migration 2026_08_23_000002_backfill_agency_id_on_rental_properties) before re-attempting this migration.'
            );
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rental_properties' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));

        if ($hasFk) {
            Schema::table('rental_properties', function (Blueprint $table) {
                $table->dropForeign(['agency_id']);
            });
        }

        Schema::table('rental_properties', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable(false)->change();
        });

        Schema::table('rental_properties', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_properties', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
        });

        Schema::table('rental_properties', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->change();
        });

        Schema::table('rental_properties', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->nullOnDelete();
        });
    }
};
