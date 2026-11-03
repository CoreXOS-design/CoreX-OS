<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-527 Ad Studio (spec .ai/specs/training-videos.md section 9.21.7). Platform records - CoreX's own marketing - so no agency_id, no tenant scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('video_ads', function (Blueprint $t) {
            $t->id();
            $t->string('feature_key', 80);
            $t->unsignedInteger('ad_number');
            $t->string('name', 40);
            $t->json('snapshot');
            $t->unsignedSmallInteger('length_seconds');
            $t->json('formats');
            $t->string('voice_key', 80)->nullable();
            $t->string('status', 12)->default('queued');   // queued | rendering | ready | failed
            $t->unsignedTinyInteger('progress_percent')->nullable();
            $t->string('stage', 20)->nullable();
            $t->string('failure_reason', 255)->nullable();
            $t->string('render_batch_id', 64)->nullable();
            $t->json('files')->nullable();
            $t->text('post_caption')->nullable();
            $t->json('post_hashtags')->nullable();
            $t->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamp('generated_at')->nullable();
            $t->timestamps();
            $t->softDeletes();

            $t->unique(['feature_key', 'ad_number']);
            $t->index(['status', 'deleted_at']);
        });

        Schema::create('ad_studio_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 80)->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_studio_settings');
        Schema::dropIfExists('video_ads');
    }
};
