<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Default commission settings (admin-controlled)
            $table->decimal('agent_cut_percent', 5, 2)->nullable()->after('branch_id');
            $table->string('paye_method', 20)->nullable()->after('agent_cut_percent'); // 'percentage' or 'fixed'
            $table->decimal('paye_value', 10, 2)->nullable()->after('paye_method');   // percent or fixed amount
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['agent_cut_percent', 'paye_method', 'paye_value']);
        });
    }
};
