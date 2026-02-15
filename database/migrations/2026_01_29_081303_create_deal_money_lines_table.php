<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('deal_money_lines', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('deal_id');
            $table->unsignedBigInteger('user_id')->nullable(); // nullable for possible company/unallocated line later
            $table->string('period', 7)->index();
            $table->unsignedBigInteger('branch_id')->nullable()->index(); // ledger branch (from deal)

            $table->string('side', 20)->nullable(); // listing|selling|null

            // Pool economics (EX VAT)
            $table->decimal('side_pool_ex_vat', 14, 2)->default(0);
            $table->decimal('allocation_percent', 6, 2)->default(0); // share of side pool assigned to this user
            $table->decimal('pool_share_ex_vat', 14, 2)->default(0); // side_pool * allocation%

            // Split economics (agent vs company)
            $table->decimal('agent_cut_percent', 6, 2)->default(0); // agent % of pool_share
            $table->decimal('agent_income_ex_vat', 14, 2)->default(0);
            $table->decimal('company_retained_ex_vat', 14, 2)->default(0);

            // PAYE + deductions
            $table->string('paye_method', 20)->nullable(); // percentage|fixed|null
            $table->decimal('paye_value', 14, 2)->default(0); // % or fixed amount depending on method
            $table->decimal('paye_amount', 14, 2)->default(0);

            $table->decimal('deductions', 14, 2)->default(0);
            $table->string('deductions_description')->nullable();

            // Agent net after PAYE + deductions
            $table->decimal('agent_net_ex_vat', 14, 2)->default(0);

            $table->dateTime('paid_at')->nullable();

            $table->timestamps();

            $table->index(['deal_id','side']);
            $table->index(['user_id','period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deal_money_lines');
    }
};
