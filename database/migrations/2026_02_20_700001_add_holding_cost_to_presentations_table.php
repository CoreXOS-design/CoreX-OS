<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->decimal('monthly_bond',             12, 2)->nullable()->after('floor_area_m2');
            $table->decimal('monthly_rates',            12, 2)->nullable()->after('monthly_bond');
            $table->decimal('monthly_levies',           12, 2)->nullable()->after('monthly_rates');
            $table->decimal('monthly_insurance',        12, 2)->nullable()->after('monthly_levies');
            $table->decimal('monthly_utilities',        12, 2)->nullable()->after('monthly_insurance');
            $table->decimal('monthly_opportunity_cost', 12, 2)->nullable()->after('monthly_utilities');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn([
                'monthly_bond',
                'monthly_rates',
                'monthly_levies',
                'monthly_insurance',
                'monthly_utilities',
                'monthly_opportunity_cost',
            ]);
        });
    }
};
