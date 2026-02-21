<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->unsignedTinyInteger('merge_confidence')->nullable()->after('source_rank');
            $table->unsignedTinyInteger('data_quality_score')->nullable()->after('merge_confidence');
            $table->json('conflict_flags_json')->nullable()->after('data_quality_score');
        });
    }

    public function down(): void
    {
        Schema::table('presentation_active_listings', function (Blueprint $table) {
            $table->dropColumn(['merge_confidence', 'data_quality_score', 'conflict_flags_json']);
        });
    }
};
