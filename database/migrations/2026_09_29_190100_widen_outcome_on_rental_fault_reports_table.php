<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rentals-faults-work-orders.md §4.4 — the new
 * 'resolved_by_first_aid' outcome value is 22 characters, past the
 * original 20-character column (2026_09_25_100000). Caught by a real
 * test run (SQLSTATE 22001, string data right truncated), not lint.
 *
 * Raw SQL, not Schema::table()->change() — this codebase doesn't depend
 * on doctrine/dbal (confirmed: not in composer.json), which Laravel's
 * fluent column-modification helper requires under the hood. Same
 * precedent as 2026_10_04_100200_widen_documents_source_type_column.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE rental_fault_reports MODIFY outcome VARCHAR(40) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE rental_fault_reports MODIFY outcome VARCHAR(20) NULL');
    }
};
