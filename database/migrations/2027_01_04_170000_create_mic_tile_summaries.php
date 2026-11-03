<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** AT-520 4.10: the face numbers of a slow MIC tile (tile 10), calculated by the nightly refresh / after-import job and STORED, so the tile row never waits. Additive. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mic_tile_summaries')) {
            Schema::create('mic_tile_summaries', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('agency_id');
                $t->string('tile', 40);
                $t->json('payload');
                $t->timestamp('computed_at')->nullable();
                $t->unsignedInteger('took_ms')->nullable();
                $t->timestamps();
                $t->unique(['agency_id', 'tile']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('mic_tile_summaries');
    }
};
