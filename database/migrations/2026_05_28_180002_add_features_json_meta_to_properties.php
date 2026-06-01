<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('features_json_meta')->nullable()->after('features_json')
                ->comment('Per-feature audit: {pool:{source:ai|manual,confidence:0.92,confirmed_by_user_id:5,confirmed_at:...}}');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('features_json_meta');
        });
    }
};
