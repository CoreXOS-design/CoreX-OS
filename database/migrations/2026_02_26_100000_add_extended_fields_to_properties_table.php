<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('city')->default('')->after('suburb');
            $table->text('excerpt')->nullable()->after('description');
            $table->json('dawn_images_json')->nullable()->after('images_json');
            $table->json('noon_images_json')->nullable()->after('dawn_images_json');
            $table->json('dusk_images_json')->nullable()->after('noon_images_json');
            $table->json('gallery_images_json')->nullable()->after('dusk_images_json');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['city', 'excerpt', 'dawn_images_json', 'noon_images_json', 'dusk_images_json', 'gallery_images_json']);
        });
    }
};
