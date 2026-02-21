<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            // Nullable so existing rows are not broken.
            // Required in controller validation for new rows.
            $table->string('suburb', 100)->nullable()->after('property_address');
            $table->string('property_type', 20)->nullable()->after('suburb');
            $table->unsignedSmallInteger('bedrooms')->nullable()->after('property_type');
            $table->unsignedSmallInteger('floor_area_m2')->nullable()->after('bedrooms');
        });
    }

    public function down(): void
    {
        Schema::table('presentations', function (Blueprint $table) {
            $table->dropColumn(['suburb', 'property_type', 'bedrooms', 'floor_area_m2']);
        });
    }
};
