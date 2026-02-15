<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('company_expenses', function (Blueprint $table) {
            if (!Schema::hasColumn('company_expenses', 'period')) {
                $table->string('period')->after('id');
            }

            if (!Schema::hasColumn('company_expenses', 'monthly_expenses')) {
                $table->decimal('monthly_expenses', 12, 2)->default(0)->after('period');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('company_expenses', function (Blueprint $table) {
            if (Schema::hasColumn('company_expenses', 'monthly_expenses')) {
                $table->dropColumn('monthly_expenses');
            }

            if (Schema::hasColumn('company_expenses', 'period')) {
                $table->dropColumn('period');
            }
        });
    }
};
