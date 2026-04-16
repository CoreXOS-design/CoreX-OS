<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('youtube_video_id', 11)->nullable()->after('pp_hide_unit_number');
            $table->string('matterport_id', 100)->nullable()->after('youtube_video_id');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn(['youtube_video_id', 'matterport_id']);
        });
    }
};
