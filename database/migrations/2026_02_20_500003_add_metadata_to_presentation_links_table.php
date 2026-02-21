<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_links', function (Blueprint $table) {
            $table->unsignedBigInteger('asking_price_inc')->nullable()->after('notes');
            $table->unsignedSmallInteger('beds')->nullable()->after('asking_price_inc');
            $table->unsignedSmallInteger('baths')->nullable()->after('beds');
            $table->unsignedSmallInteger('floor_area_m2')->nullable()->after('baths');
            $table->unsignedSmallInteger('erf_m2')->nullable()->after('floor_area_m2');
            $table->string('property_type', 30)->nullable()->after('erf_m2');
            $table->string('suburb', 100)->nullable()->after('property_type');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_links', function (Blueprint $table) {
            $table->dropColumn([
                'asking_price_inc', 'beds', 'baths',
                'floor_area_m2', 'erf_m2', 'property_type', 'suburb',
            ]);
        });
    }
};
