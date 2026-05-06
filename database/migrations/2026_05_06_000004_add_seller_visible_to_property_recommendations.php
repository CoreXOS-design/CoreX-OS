<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('property_recommendations', function (Blueprint $table) {
            $table->boolean('seller_visible')->default(true)->after('seller_facing_reasoning');
        });
    }

    public function down(): void
    {
        Schema::table('property_recommendations', function (Blueprint $table) {
            $table->dropColumn('seller_visible');
        });
    }
};
