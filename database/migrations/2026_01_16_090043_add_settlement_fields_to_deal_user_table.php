<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deal_user', function (Blueprint $table) {
            // ZIP-style per-deal settlement overrides (nullable = exception-based)
            $table->decimal('agent_cut_percent', 8, 2)->nullable(); // agent cut % of allocated
            $table->string('paye_method')->nullable();             // 'percentage' | 'fixed'
            $table->decimal('paye_value', 12, 2)->nullable();       // % or fixed value
            $table->decimal('deductions', 12, 2)->nullable();       // deductions amount
            $table->string('deductions_description')->nullable();   // reason/notes

            // Optional: mark agent-side payment (not required yet, but useful)
            $table->datetime('paid_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deal_user', function (Blueprint $table) {
            $table->dropColumn([
                'agent_cut_percent',
                'paye_method',
                'paye_value',
                'deductions',
                'deductions_description',
                'paid_at',
            ]);
        });
    }
};
