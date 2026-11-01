<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-528 (mic-work-tab.md 13.2): each user's last MIC view (adverts | list) and last filter set. Additive; one row per user per agency. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mic_user_preferences')) {
            Schema::create('mic_user_preferences', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->unsignedBigInteger('user_id');
                $t->string('view', 12)->nullable();
                $t->json('filters')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_user_preferences');
    }
};
