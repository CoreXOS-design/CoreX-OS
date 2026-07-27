<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            // The FULL tag order (derived room tags + custom tags together)
            // exactly as the user arranged them in the gallery sort-order UI.
            // gallery_custom_tags only records which strings are custom and
            // their order relative to each other — it can't represent a
            // derived tag being dragged, or a custom tag being interleaved
            // among derived tags. Property::getAvailableGalleryTags() applies
            // this order on top of the merged tag list; a tag absent from
            // this array (e.g. a room added after the last sort) is appended
            // at the end rather than hidden.
            $table->json('gallery_tag_order')->nullable()->after('gallery_custom_tags');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('gallery_tag_order');
        });
    }
};
