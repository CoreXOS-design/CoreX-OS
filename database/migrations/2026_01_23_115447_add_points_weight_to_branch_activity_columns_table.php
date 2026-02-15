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
        Schema::table('branch_activity_columns', function (Blueprint $table) {
            $table->decimal('points_weight', 8, 2)->nullable()->after('sort_order');
        });
    }

        public function down(): void
    {
        Schema::table('branch_activity_columns', function (Blueprint $table) {
            $table->dropColumn('points_weight');
        });
    }

};
