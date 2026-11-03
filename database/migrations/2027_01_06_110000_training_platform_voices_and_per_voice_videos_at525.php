<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-525 (spec .ai/specs/training-videos.md section 9.20): CoreX voices made by the system owner (Chatterbox, from a consented reference clip), available to every agency; and
// one generated video PER (screen, voice) so agencies on the same voice share one set and a different voice is generated once, never per agency.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_platform_voices', function (Blueprint $t) {
            $t->id();
            $t->string('voice_key', 60)->unique();                // 'corex_<slug>' - the key an agency's training_video_voice may hold
            $t->string('name', 80);
            $t->string('about', 200)->nullable();
            $t->string('reference_file', 200);                    // private disk, never served to an agency
            $t->decimal('reference_seconds', 5, 1)->nullable();
            $t->timestamp('consent_confirmed_at');                // "the person in this recording agreed to their voice being used"
            $t->foreignId('consent_by_user_id')->nullable();
            $t->boolean('is_default')->default(false);            // the platform default: every agency uses it unless its admin chooses another
            $t->boolean('is_active')->default(true);
            $t->foreignId('created_by_user_id')->nullable();
            $t->softDeletes();
            $t->timestamps();
        });

        Schema::table('training_videos', function (Blueprint $t) {
            $t->string('voice_key', 60)->nullable()->after('screen_key');   // null = made before voices were per-video (legacy: any agency may still watch it)
            $t->index(['screen_key', 'voice_key'], 'training_videos_screen_voice_idx');
        });
    }

    public function down(): void
    {
        Schema::table('training_videos', function (Blueprint $t) {
            $t->dropIndex('training_videos_screen_voice_idx');
            $t->dropColumn('voice_key');
        });
        Schema::dropIfExists('training_platform_voices');
    }
};
