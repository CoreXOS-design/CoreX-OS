<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase-2 branch-isolation: add a nullable `branch_id` column to every
 * agency-tenanted table that does not yet have one AND whose records
 * should be branch-scoped when Split Branches is ON.
 *
 * Shared-scope tables (training_courses, commission_settings, knowledge
 * base, agency-level config, prospecting tables, RMCP versions, P24
 * system tables, web_packs, cds_drafts, agency_signing_parties) are
 * intentionally NOT included — they stay agency-wide per spec §7 + §14.
 *
 * NULL backfill. Legacy rows stay NULL and will only be visible to
 * `branches.view_all` holders once Split is ON (spec §8).
 */
return new class extends Migration
{
    /**
     * Tables that get a nullable branch_id FK + composite agency/branch index.
     */
    private const TABLES = [
        'contacts',
        'documents',
        'fica_submissions',
        'employee_screenings',
        'user_compliance_overrides',
        'rmcp_acknowledgements',
        'agent_applications',
        'user_documents',
        'commission_ledger',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                $t->foreignId('branch_id')
                    ->nullable()
                    ->after('agency_id')
                    ->constrained('branches')
                    ->nullOnDelete();

                // Composite index speeds up scope queries (agency + branch)
                if (Schema::hasColumn($table, 'agency_id')) {
                    $t->index(['agency_id', 'branch_id'], "{$table}_agency_branch_idx");
                }
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if (!Schema::hasColumn($table, 'branch_id')) {
                continue;
            }

            Schema::table($table, function (Blueprint $t) use ($table) {
                // drop index first (if present) before dropping the FK/column
                try {
                    $t->dropIndex("{$table}_agency_branch_idx");
                } catch (\Throwable $e) {
                    // index may not exist on environments that only had the column
                }
                $t->dropConstrainedForeignId('branch_id');
            });
        }
    }
};
