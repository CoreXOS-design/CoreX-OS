<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('category')->nullable()->after('property_type');
            $table->unsignedBigInteger('rates_taxes')->nullable()->after('price');
            $table->unsignedBigInteger('levy')->nullable()->after('rates_taxes');
            $table->unsignedBigInteger('special_levy')->nullable()->after('levy');
            $table->json('features_json')->nullable()->after('gallery_images_json');
            $table->string('address')->nullable()->after('suburb');
            $table->date('listed_date')->nullable()->after('published_at');
            $table->date('expiry_date')->nullable()->after('listed_date');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn([
                'category', 'rates_taxes', 'levy', 'special_levy',
                'features_json', 'address', 'listed_date', 'expiry_date',
            ]);
        });
    }
};
