<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // User-defined gallery tags that aren't derived from spaces.
            // Stored as a JSON array of strings (e.g. ["Garden View","Beach Front"]).
            // Merged into Property::getAvailableGalleryTags() so the gallery UI
            // surfaces them alongside the auto-derived tags.
            $table->json('gallery_custom_tags')->nullable()->after('gallery_categories_json');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('gallery_custom_tags');
        });
    }
};
