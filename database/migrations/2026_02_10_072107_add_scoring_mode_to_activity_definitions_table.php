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
        Schema::table('activity_definitions', function (Blueprint $table) {
            // scoring_mode:
            // - count: user enters quantity (value * weight)
            // - once: user ticks checkbox (stores 1, scores weight)
            $table->string('scoring_mode', 20)->default('count')->after('sort_order');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_definitions', function (Blueprint $table) {
            $table->dropColumn('scoring_mode');
        });
    }
};
