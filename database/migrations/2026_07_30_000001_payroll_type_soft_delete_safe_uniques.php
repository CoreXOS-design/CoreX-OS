<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-237 A3 — the soft-delete-blind unique class, sibling tables. payroll_earning_types
 * and payroll_deduction_types are SoftDeletes with a (agency_id, code) unique that counts
 * trashed rows, so soft-deleting a type and re-creating the same code hits a 1062. Same
 * active-key fix as A1/A2: the key holds `code` only while the row is live; MySQL ignores
 * NULLs, so a soft-deleted type frees its code.
 *
 * (payroll_employees is intentionally NOT changed: it has no SoftDeletes trait — deletes
 * are hard — and its (agency_id, user_id) unique correctly enforces one payroll record per
 * user, with re-hire modelled as reactivation, not a second row.)
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add the new unique FIRST — its leftmost `agency_id` keeps the agency FK's index
        // requirement satisfied, so dropping the old (agency_id, code) unique won't 1553.
        foreach (['payroll_earning_types', 'payroll_deduction_types'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN active_code_key VARCHAR(64) "
                . "GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN code ELSE NULL END) STORED");
            Schema::table($table, fn (Blueprint $t) => $t->unique(['agency_id', 'active_code_key'], "{$table}_active_code_uq"));
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique("{$table}_agency_id_code_unique"));
        }
    }

    public function down(): void
    {
        foreach (['payroll_earning_types', 'payroll_deduction_types'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->unique(['agency_id', 'code'], "{$table}_agency_id_code_unique"));
            Schema::table($table, fn (Blueprint $t) => $t->dropUnique("{$table}_active_code_uq"));
            DB::statement("ALTER TABLE {$table} DROP COLUMN active_code_key");
        }
    }
};
