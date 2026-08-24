<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security fix — `company_expenses` (App\Models\CompanyExpense) has never
 * carried an `agency_id` column, and the model has zero tenant scoping.
 * CompanySummaryController::index() does
 * `CompanyExpense::firstOrCreate(['period' => $period], ...)` keyed ONLY by
 * period, so every agency on the platform viewing the Company Summary page
 * for the same month shares (and can silently overwrite) a single global
 * monthly_expenses row — one agency's saved expense figure leaks into, and
 * can be clobbered by, every other agency's cashflow calculation for that
 * period.
 *
 * Adds the column nullable + FK + index, mirroring the rental_properties/
 * docuperfect_documents precedent (2026_08_23_000001 / _000004). Backfill
 * happens in the next migration; NOT NULL is applied in the migration after
 * that, guarded on zero remaining NULLs.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('company_expenses', 'agency_id')) {
            Schema::table('company_expenses', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'company_expenses' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));
        if (!$hasFk) {
            Schema::table('company_expenses', function (Blueprint $table) {
                $table->foreign('agency_id')
                      ->references('id')->on('agencies')
                      ->nullOnDelete();
            });
        }

        $hasIndex = !empty(DB::select(
            "SHOW INDEX FROM company_expenses WHERE Key_name = 'idx_company_expenses_agency_id'"
        ));
        if (!$hasIndex) {
            Schema::table('company_expenses', function (Blueprint $table) {
                $table->index('agency_id', 'idx_company_expenses_agency_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('company_expenses', 'agency_id')) {
            Schema::table('company_expenses', function (Blueprint $table) {
                try { $table->dropForeign(['agency_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex('idx_company_expenses_agency_id'); } catch (\Throwable $e) {}
                $table->dropColumn('agency_id');
            });
        }
    }
};
