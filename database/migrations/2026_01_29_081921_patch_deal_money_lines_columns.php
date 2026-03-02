<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        $table = "deal_money_lines";

        Schema::table($table, function (Blueprint $t) use ($table) {
            if (!Schema::hasColumn($table, 'side_pool_ex_vat'))    $t->decimal('side_pool_ex_vat',    14, 2)->default(0);
            if (!Schema::hasColumn($table, 'allocation_percent'))  $t->decimal('allocation_percent',  6,  2)->default(0);
            if (!Schema::hasColumn($table, 'pool_share_ex_vat'))   $t->decimal('pool_share_ex_vat',   14, 2)->default(0);
            if (!Schema::hasColumn($table, 'agent_cut_percent'))   $t->decimal('agent_cut_percent',   6,  2)->default(0);
            if (!Schema::hasColumn($table, 'agent_gross_ex_vat'))  $t->decimal('agent_gross_ex_vat',  14, 2)->default(0);
            if (!Schema::hasColumn($table, 'company_gross_ex_vat'))$t->decimal('company_gross_ex_vat',14, 2)->default(0);
            if (!Schema::hasColumn($table, 'paye_method'))         $t->string('paye_method', 20)->nullable();
            if (!Schema::hasColumn($table, 'paye_value'))          $t->decimal('paye_value',  14, 2)->default(0);
            if (!Schema::hasColumn($table, 'paye_amount'))         $t->decimal('paye_amount', 14, 2)->default(0);
            if (!Schema::hasColumn($table, 'deductions'))          $t->decimal('deductions',  14, 2)->default(0);
            if (!Schema::hasColumn($table, 'deductions_description'))$t->string('deductions_description')->nullable();
            if (!Schema::hasColumn($table, 'agent_net_ex_vat'))    $t->decimal('agent_net_ex_vat', 14, 2)->default(0);
            if (!Schema::hasColumn($table, 'source'))              $t->string('source', 30)->nullable();
            if (!Schema::hasColumn($table, 'paid_at'))             $t->dateTime('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        // SQLite can't drop columns safely in-place; skip.
    }
};
