<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Make `company_expenses.agency_id` NOT NULL, per the spec's pillar-table
 * pattern (see .ai/specs/multi-tenancy.md, rental_properties precedent).
 *
 * Guarded: refuses to advance if any row is still NULL (a period with no
 * matching worksheets to infer an agency from, or a row inserted between
 * the previous migration and this one). If this throws, do NOT force the
 * column NOT NULL by hand — investigate the orphan rows (they are
 * invisible to every tenant under AgencyScope, not "shared") and either
 * assign an agency_id manually or delete the stale global row before
 * re-running this migration.
 */
return new class extends Migration {
    public function up(): void
    {
        $stillNull = DB::table('company_expenses')->whereNull('agency_id')->count();
        if ($stillNull > 0) {
            throw new \RuntimeException(
                "company_expenses still has {$stillNull} row(s) with NULL agency_id. "
                . 'Investigate periods with no matching worksheets (or re-run migration 2026_08_24_000002_backfill_agency_id_on_company_expenses) before re-attempting this migration.'
            );
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'company_expenses' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));

        if ($hasFk) {
            Schema::table('company_expenses', function (Blueprint $table) {
                $table->dropForeign(['agency_id']);
            });
        }

        Schema::table('company_expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable(false)->change();
        });

        Schema::table('company_expenses', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('company_expenses', function (Blueprint $table) {
            $table->dropForeign(['agency_id']);
        });

        Schema::table('company_expenses', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable()->change();
        });

        Schema::table('company_expenses', function (Blueprint $table) {
            $table->foreign('agency_id')
                  ->references('id')->on('agencies')
                  ->nullOnDelete();
        });
    }
};
