<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Security fix — `tv_access_codes` has never carried an `agency_id` column.
 * `TvAccessCode` has no tenant boundary at all, so:
 *
 *   1. Admin\TvCodeController::generate()/revoke() validate `branch_id`/
 *      `code_id` with Laravel's `exists:` rule only, which is an unscoped
 *      existence check — any admin with `manage_tv_messages` (not
 *      owner-only) can mint a public TV code for another agency's branch,
 *      or revoke another agency's live codes.
 *   2. `generateCompany()`/`revokeCompany()` operate on `branch_id IS NULL`
 *      with no agency dimension whatsoever — every agency shares ONE flat
 *      pool of "company" codes, so revoking a company code deactivates
 *      every agency's company code, not just the caller's.
 *
 * Adds the column nullable + FK + index, mirroring the docuperfect_documents
 * precedent (2026_08_23_000004). Backfill happens in the next migration;
 * NOT NULL is applied in the migration after that, guarded on zero
 * remaining NULLs.
 *
 * Finding C1, .ai/audits/cross-agency-isolation-audit-2026-08-20.md.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('tv_access_codes', 'agency_id')) {
            Schema::table('tv_access_codes', function (Blueprint $table) {
                $table->unsignedBigInteger('agency_id')->nullable()->after('id');
            });
        }

        $hasFk = !empty(DB::select(
            "SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS "
            . "WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tv_access_codes' "
            . "AND REFERENCED_TABLE_NAME = 'agencies'"
        ));
        if (!$hasFk) {
            Schema::table('tv_access_codes', function (Blueprint $table) {
                $table->foreign('agency_id')
                      ->references('id')->on('agencies')
                      ->nullOnDelete();
            });
        }

        $hasIndex = !empty(DB::select(
            "SHOW INDEX FROM tv_access_codes WHERE Key_name = 'idx_tv_access_codes_agency_id'"
        ));
        if (!$hasIndex) {
            Schema::table('tv_access_codes', function (Blueprint $table) {
                $table->index('agency_id', 'idx_tv_access_codes_agency_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tv_access_codes', 'agency_id')) {
            Schema::table('tv_access_codes', function (Blueprint $table) {
                try { $table->dropForeign(['agency_id']); } catch (\Throwable $e) {}
                try { $table->dropIndex('idx_tv_access_codes_agency_id'); } catch (\Throwable $e) {}
                $table->dropColumn('agency_id');
            });
        }
    }
};
