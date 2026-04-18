<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (!Schema::hasColumn('properties', 'pet_friendly')) {
                // Nullable so we can express yes / no / unknown (null).
                $table->boolean('pet_friendly')->nullable()->after('features_json');
            }
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            if (Schema::hasColumn('properties', 'pet_friendly')) {
                $table->dropColumn('pet_friendly');
            }
        });
    }
};
