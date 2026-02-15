<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    private function colExists(string $table, string $col): bool
    {
        $cols = DB::select("PRAGMA table_info($table)");
        foreach ($cols as $c) {
            if (($c->name ?? null) === $col) return true;
        }
        return false;
    }

    public function up(): void
    {
        // Add only missing columns (SQLite: ALTER TABLE ADD COLUMN only)
        $table = "deal_money_lines";

        $adds = [
            ["side_pool_ex_vat", "decimal(14,2) NOT NULL DEFAULT 0"],
            ["allocation_percent", "decimal(6,2) NOT NULL DEFAULT 0"],
            ["pool_share_ex_vat", "decimal(14,2) NOT NULL DEFAULT 0"],

            ["agent_cut_percent", "decimal(6,2) NOT NULL DEFAULT 0"],
            ["agent_gross_ex_vat", "decimal(14,2) NOT NULL DEFAULT 0"],
            ["company_gross_ex_vat", "decimal(14,2) NOT NULL DEFAULT 0"],

            ["paye_method", "varchar(20) NULL"],
            ["paye_value", "decimal(14,2) NOT NULL DEFAULT 0"],
            ["paye_amount", "decimal(14,2) NOT NULL DEFAULT 0"],

            ["deductions", "decimal(14,2) NOT NULL DEFAULT 0"],
            ["deductions_description", "varchar(255) NULL"],

            ["agent_net_ex_vat", "decimal(14,2) NOT NULL DEFAULT 0"],

            ["source", "varchar(30) NULL"],
            ["paid_at", "datetime NULL"],
        ];

        foreach ($adds as [$col, $sqlType]) {
            if (!$this->colExists($table, $col)) {
                DB::statement("ALTER TABLE $table ADD COLUMN $col $sqlType");
            }
        }
    }

    public function down(): void
    {
        // SQLite can't drop columns safely in-place; skip.
    }
};
