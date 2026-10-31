<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-525 (spec .ai/specs/training-videos.md section 9.12): the voice an agency has chosen for its how-to videos (a key of config training-videos.auto.voices; null = the default).
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->string('training_video_voice', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $t) {
            $t->dropColumn('training_video_voice');
        });
    }
};
