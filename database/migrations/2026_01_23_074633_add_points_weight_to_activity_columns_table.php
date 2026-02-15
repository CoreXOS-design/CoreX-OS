<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_columns', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_columns', 'points_weight')) {
                // Admin-maintained "value" / weighting per activity
                // Example: calls=1, viewing=5, offer=20, sale=50 etc.
                $table->decimal('points_weight', 10, 2)->default(1.00)->after('label');
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_columns', function (Blueprint $table) {
            if (Schema::hasColumn('activity_columns', 'points_weight')) {
                $table->dropColumn('points_weight');
            }
        });
    }
};
