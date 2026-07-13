<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AT-237 Batch 3 (A1/A2/D3) — soft-delete/status-blind unique constraints.
 *
 * The payroll uniques counted cancelled AND soft-deleted rows, so cancel-and-redo
 * a month hit a 1062 → 500 (the walk error Johan hit), and payslip_number was
 * GLOBALLY unique (a 2nd agency's first payslip collides). Fix the class with
 * "active-key" generated columns: the key holds the natural key ONLY while the row
 * is live (not cancelled, not soft-deleted); MySQL ignores NULLs in a unique index,
 * so any number of cancelled/deleted rows coexist while one live row is guaranteed.
 * Plus D3: payslip lines gain SoftDeletes (were hard-deleted — non-negotiable #1).
 */
return new class extends Migration
{
    public function up(): void
    {
        // A1 — payroll_runs: at most ONE live (draft/finalised) run per (agency, month);
        // cancelled/soft-deleted runs free the month.
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropUnique('payroll_runs_agency_id_period_month_unique'));
        DB::statement(
            "ALTER TABLE payroll_runs ADD COLUMN active_period_key DATE "
            . "GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL AND status <> 'cancelled' THEN period_month ELSE NULL END) STORED"
        );
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->unique(['agency_id', 'active_period_key'], 'payroll_runs_active_period_unique'));

        // A2 — payroll_payslips: payslip_number unique was GLOBAL (cross-agency 500) + soft-delete-blind.
        // Scope to agency, live rows only.
        Schema::table('payroll_payslips', fn (Blueprint $t) => $t->dropUnique('payroll_payslips_payslip_number_unique'));
        DB::statement(
            "ALTER TABLE payroll_payslips ADD COLUMN active_payslip_key VARCHAR(64) "
            . "GENERATED ALWAYS AS (CASE WHEN deleted_at IS NULL THEN payslip_number ELSE NULL END) STORED"
        );
        Schema::table('payroll_payslips', fn (Blueprint $t) => $t->unique(['agency_id', 'active_payslip_key'], 'payroll_payslips_active_number_unique'));

        // D3 — payroll_payslip_lines were HARD-deleted (financial audit records; non-negotiable #1).
        if (! Schema::hasColumn('payroll_payslip_lines', 'deleted_at')) {
            Schema::table('payroll_payslip_lines', fn (Blueprint $t) => $t->softDeletes());
        }
    }

    public function down(): void
    {
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->dropUnique('payroll_runs_active_period_unique'));
        DB::statement('ALTER TABLE payroll_runs DROP COLUMN active_period_key');
        Schema::table('payroll_runs', fn (Blueprint $t) => $t->unique(['agency_id', 'period_month'], 'payroll_runs_agency_id_period_month_unique'));

        Schema::table('payroll_payslips', fn (Blueprint $t) => $t->dropUnique('payroll_payslips_active_number_unique'));
        DB::statement('ALTER TABLE payroll_payslips DROP COLUMN active_payslip_key');
        Schema::table('payroll_payslips', fn (Blueprint $t) => $t->unique('payslip_number', 'payroll_payslips_payslip_number_unique'));

        if (Schema::hasColumn('payroll_payslip_lines', 'deleted_at')) {
            Schema::table('payroll_payslip_lines', fn (Blueprint $t) => $t->dropSoftDeletes());
        }
    }
};
