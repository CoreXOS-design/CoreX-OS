<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('property_number', 100)->nullable()->after('erf_size_m2');
            $table->string('complex_name', 255)->nullable()->after('property_number');
            $table->string('unit_number', 100)->nullable()->after('complex_name');
            $table->string('district', 255)->nullable()->after('region');
            $table->decimal('rental_amount', 12, 2)->nullable()->after('special_levy');
            $table->decimal('deposit_amount', 12, 2)->nullable()->after('rental_amount');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('deposit_amount');
            $table->decimal('admin_fee', 12, 2)->nullable()->after('commission_percent');
            $table->decimal('marketing_fee', 12, 2)->nullable()->after('admin_fee');
            $table->date('lease_start_date')->nullable()->after('expiry_date');
            $table->date('lease_end_date')->nullable()->after('lease_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'property_number', 'complex_name', 'unit_number', 'district',
                'rental_amount', 'deposit_amount', 'commission_percent',
                'admin_fee', 'marketing_fee', 'lease_start_date', 'lease_end_date',
            ]);
        });
    }
};
