<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->string('cma_selected_range', 10)->default('middle')->after('monthly_opportunity_cost');
            $table->string('vicinity_selected_range', 10)->default('middle')->after('cma_selected_range');
            $table->text('excluded_active_listing_indices')->nullable()->after('vicinity_selected_range');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn(['cma_selected_range', 'vicinity_selected_range', 'excluded_active_listing_indices']);
        });
    }
};
