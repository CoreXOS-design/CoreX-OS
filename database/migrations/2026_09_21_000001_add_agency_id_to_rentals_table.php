<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-424 — `rentals` (App\Models\Rental) never carried an `agency_id`. The
 * model only used BelongsToBranch, whose BranchScope is a no-op unless the
 * agency has split branches enabled, and RentalsController applies no filter
 * for the 'all' data scope. Result (found by the 2026-09-21 agency scan): a
 * brand-new agency's Admin saw every agency's Rentals Register — addresses,
 * rent, commission — and could open and save any rental by id.
 *
 * Adds the column, backfills it from the rental's branch (rentals.branch_id is
 * NOT NULL + FK, so every row resolves), then makes it NOT NULL. Same shape as
 * the rental_properties / docuperfect_documents precedent (2026_08_23_*),
 * folded into one migration because the backfill cannot leave orphans.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('rentals', 'agency_id')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        DB::update(
            'UPDATE rentals r '
            . 'INNER JOIN branches b ON b.id = r.branch_id '
            . 'SET r.agency_id = b.agency_id '
            . 'WHERE r.agency_id IS NULL AND b.agency_id IS NOT NULL'
        );

        $stillNull = DB::table('rentals')->whereNull('agency_id')->count();
        if ($stillNull > 0) {
            throw new \RuntimeException(
                "rentals still has {$stillNull} row(s) with NULL agency_id after the branch backfill. "
                . 'Investigate rentals whose branch has no agency before re-running this migration.'
            );
        }

        Schema::table('rentals', function (Blueprint $table) {
            $table->unsignedBigInteger('agency_id')->nullable(false)->change();
        });

        $hasIndex = !empty(DB::select("SHOW INDEX FROM rentals WHERE Key_name = 'idx_rentals_agency_id'"));
        if (!$hasIndex) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->index('agency_id', 'idx_rentals_agency_id');
            });
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'rentals' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));
        if (!$hasFk) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->foreign('agency_id')->references('id')->on('agencies')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rentals', 'agency_id')) {
            Schema::table('rentals', function (Blueprint $table) {
                try { $table->dropForeign(['agency_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex('idx_rentals_agency_id'); } catch (\Throwable $e) {}
                $table->dropColumn('agency_id');
            });
        }
    }
};
