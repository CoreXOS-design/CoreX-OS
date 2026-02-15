<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            if (!Schema::hasColumn('targets', 'points_target')) {
                $table->integer('points_target')->default(0)->after('value_target');
            }
        });
    }

    public function down(): void
    {
        Schema::table('targets', function (Blueprint $table) {
            if (Schema::hasColumn('targets', 'points_target')) {
                $table->dropColumn('points_target');
            }
        });
    }
};
