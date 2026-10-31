<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// AT-525 (spec .ai/specs/training-videos.md 9.24): platform-level settings of the training-video system. The one that matters: `system_voice` - the ONE voice every
// how-to video, narrated-tour clip and ad is spoken in (no agency choice). Platform record: no agency_id.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_settings', function (Blueprint $t) {
            $t->id();
            $t->string('key', 60)->unique();
            $t->text('value')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_settings');
    }
};
