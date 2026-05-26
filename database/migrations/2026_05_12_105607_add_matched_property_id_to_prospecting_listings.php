<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->foreignId('matched_property_id')
                  ->nullable()
                  ->after('id')
                  ->constrained('properties')
                  ->nullOnDelete();
            $table->timestamp('matched_at')->nullable()->after('matched_property_id');
            $table->index('matched_property_id');
        });
    }

    public function down(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropForeign(['matched_property_id']);
            $table->dropColumn(['matched_property_id', 'matched_at']);
        });
    }
};
