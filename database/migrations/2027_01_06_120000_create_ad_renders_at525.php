<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-525 / AT-527 (spec .ai/specs/training-videos.md 9.16.1 + 9.16.2): the renders of Ad Studio's video ads - one row per (batch, format). Platform records (CoreX's own marketing):
// no agency_id, like training_videos. The renderer owns this table; Ad Studio reads it through AdRenderer::status() or the AdRenderFinished event.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ad_renders', function (Blueprint $t) {
            $t->id();
            $t->string('batch_id', 40)->index();
            $t->unsignedBigInteger('ad_ref')->nullable()->index();    // video_ads.id (integer) - only echoed back
            $t->string('format', 8);                                   // 9:16 | 1:1 | 16:9
            $t->string('status', 12)->default('queued');               // queued | rendering | ready | failed
            $t->string('stage', 60)->nullable();
            $t->unsignedTinyInteger('progress_percent')->default(0);
            $t->string('file_name', 200)->nullable();                  // private disk path of the mp4
            $t->string('poster_name', 200)->nullable();               // private disk path of the poster jpg
            $t->decimal('duration_seconds', 6, 1)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->unsignedSmallInteger('width')->nullable();
            $t->unsignedSmallInteger('height')->nullable();
            $t->string('voice_key', 60)->nullable();
            $t->string('source_hash', 40)->nullable()->index();        // script + voice + brand + steps: the same hash is never rendered twice
            $t->text('failure_reason')->nullable();
            $t->timestamp('rendered_at')->nullable();
            $t->softDeletes();
            $t->timestamps();
            $t->unique(['batch_id', 'format']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_renders');
    }
};
