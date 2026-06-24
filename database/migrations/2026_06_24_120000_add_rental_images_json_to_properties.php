<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->json('rental_images_json')->nullable()->after('gallery_custom_tags')
                ->comment('Rental inspection galleries: {in_inspection:{date,images[]}, out_inspection:{date,images[]}, custom:[{id,name,date,images[]}]}. Only used when listing_type=rental.');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('rental_images_json');
        });
    }
};
