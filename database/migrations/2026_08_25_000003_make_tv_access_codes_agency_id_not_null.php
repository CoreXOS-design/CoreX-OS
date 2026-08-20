<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `tv_access_codes.agency_id` NOT NULL, per the spec's pillar-table
 * pattern (see .ai/specs/multi-tenancy.md).
 *
 * Guarded: refuses to advance if any row is still NULL (an orphaned
 * company-wide code whose creator was deleted, or a row inserted between
 * the previous migration and this one). If this throws, do NOT force the
 * column NOT NULL by hand — investigate the orphan rows (they are
 * effectively unusable once TvAccessCode is agency-scoped, since no
 * authenticated admin action can find them) and either assign them an
 * agency_id or revoke them (`is_active = false`) before re-running this
 * migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $stillNull = DB::table('tv_access_codes')->whereNull('agency_id')->count();
        if ($stillNull > 0) {
            throw new \RuntimeException(
                "tv_access_codes still has {$stillNull} row(s) with NULL agency_id. "
                . 'Investigate orphaned company codes (or re-run migration 2026_08_25_000002_backfill_agency_id_on_tv_access_codes) before re-attempting this migration.'
            );
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_access_codes' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));

        if ($hasFk) {
            Schema::table('tv_access_codes', function (Blueprint $table) {
                $table->dropForeign(['agency_id']);
            });
        }

        Schema::table('tv_access_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable(false)->change();
        });

        Schema::table('tv_access_codes', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tv_access_codes', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
        });

        Schema::table('tv_access_codes', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->change();
        });

        Schema::table('tv_access_codes', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->nullOnDelete();
        });
    }
};
