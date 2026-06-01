<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('ai_voice_enabled')->default(false)->after('button_color')
                ->comment('Allow agents at this agency to use Ellie voice commands (advanced feature)');
            $table->boolean('ai_image_recognition_enabled')->default(false)->after('ai_voice_enabled')
                ->comment('Allow agents at this agency to use AI property image recognition (advanced feature, mobile-only)');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['ai_voice_enabled', 'ai_image_recognition_enabled']);
        });
    }
};
