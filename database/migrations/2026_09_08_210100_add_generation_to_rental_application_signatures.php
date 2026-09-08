<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reopen/resubmit, 2026-09-08 — THE signature landmine, fixed as part of
 * this build, not after (Johan, explicit non-negotiable). Before this
 * migration, storeSignature() keys updateOrCreate() on
 * (rental_application_id, kind) alone — a resubmit after reopen would
 * silently OVERWRITE the original signed image in place, destroying the
 * exact evidence a reopen must preserve ("a signature collected against
 * different content is worthless" only holds if the OLD one is still
 * there to compare against).
 *
 * `generation` joins the unique key: every submission round gets its own
 * row per kind, never overwritten. Existing rows backfill to 1 (every
 * application currently sits at current_generation=1, per the sibling
 * migration), so this is a pure widen — no existing signature moves or
 * changes meaning.
 *
 * Soft-deletable per CLAUDE.md Non-negotiable #1 (no hard deletes,
 * anywhere) even though nothing in this build ever calls delete() on a
 * signature row — added for schema-wide consistency with that rule, not
 * because a delete path exists today.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guarded with hasColumn/hasIndex throughout — a MySQL DDL statement
        // is not transactional, so a mid-run failure (this migration hit two
        // while it was first being written: an FK-index gap, then an
        // identifier-too-long error) leaves prior ALTERs in this same up()
        // already committed. Re-running from scratch must not re-collide
        // with its own earlier partial progress.
        if (! Schema::hasColumn('rental_application_signatures', 'generation')) {
            Schema::table('rental_application_signatures', function (Blueprint $table) {
                $table->unsignedInteger('generation')->default(1)->after('kind');
            });
        }
        if (! Schema::hasColumn('rental_application_signatures', 'deleted_at')) {
            Schema::table('rental_application_signatures', function (Blueprint $table) {
                $table->softDeletes();
            });
        }

        // New composite unique added BEFORE the old two-column one is
        // dropped — the old unique is the FK's only supporting index on
        // rental_application_id (both start with that column), so dropping
        // it first leaves the FK unsupported and MySQL refuses (error
        // 1553). Adding the new one first keeps the FK covered throughout.
        if (! $this->indexExists('rental_application_signatures', 'ra_signatures_app_kind_gen_unique')) {
            Schema::table('rental_application_signatures', function (Blueprint $table) {
                $table->unique(['rental_application_id', 'kind', 'generation'], 'ra_signatures_app_kind_gen_unique');
            });
        }

        if ($this->indexExists('rental_application_signatures', 'rental_application_signatures_rental_application_id_kind_unique')) {
            Schema::table('rental_application_signatures', function (Blueprint $table) {
                $table->dropUnique(['rental_application_id', 'kind']);
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $rows = \Illuminate\Support\Facades\DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

        return count($rows) > 0;
    }

    public function down(): void
    {
        Schema::table('rental_application_signatures', function (Blueprint $table) {
            $table->unique(['rental_application_id', 'kind']);
        });

        Schema::table('rental_application_signatures', function (Blueprint $table) {
            $table->dropUnique('ra_signatures_app_kind_gen_unique');
            $table->dropColumn(['generation', 'deleted_at']);
        });
    }
};
