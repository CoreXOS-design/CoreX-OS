<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_activities', function (Blueprint $table) {
            // SQLite-safe: only add if missing
            if (!Schema::hasColumn('daily_activities', 'prospecting')) {
                $table->unsignedInteger('prospecting')->default(0);
            }
        });
    }

    public function down(): void
    {
        Schema::table('daily_activities', function (Blueprint $table) {
            if (Schema::hasColumn('daily_activities', 'prospecting')) {
                $table->dropColumn('prospecting');
            }
        });
    }
};
