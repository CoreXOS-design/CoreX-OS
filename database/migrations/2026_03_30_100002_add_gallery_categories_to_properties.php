<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('properties', 'gallery_categories_json')) {
            Schema::table('properties', function (Blueprint $table) {
                $table->json('gallery_categories_json')->nullable()->after('gallery_images_json');
            });
        }
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('gallery_categories_json');
        });
    }
};
