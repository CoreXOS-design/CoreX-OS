<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('monthly_target_goals', function (Blueprint $table) {
            if (!Schema::hasColumn('monthly_target_goals', 'branch_budget')) {
                $table->decimal('branch_budget', 12, 2)->default(0)->after('value_target');
            }
        });
    }

    public function down(): void
    {
        Schema::table('monthly_target_goals', function (Blueprint $table) {
            if (Schema::hasColumn('monthly_target_goals', 'branch_budget')) {
                $table->dropColumn('branch_budget');
            }
        });
    }
};
