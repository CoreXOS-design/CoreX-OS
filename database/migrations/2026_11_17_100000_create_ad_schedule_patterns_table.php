<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-456 D.9 — "Schedule by agent" in the Ad Manager (Johan 2026-10-19). Remembers the last pattern a user scheduled
 * with (ticked weekdays, first time, gap between agents, agent order), per user per agency, so next week's batch
 * opens with the same choices. One row per (agency, user); written when a batch is scheduled, never hard-deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ad_schedule_patterns')) {
            return;
        }

        Schema::create('ad_schedule_patterns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('weekdays');                       // ISO weekdays 1 (Mon) .. 7 (Sun)
            $table->string('first_time', 5);                // local HH:MM
            $table->unsignedSmallInteger('gap_minutes');
            $table->string('agent_order', 10);              // alpha | custom
            $table->json('custom_agent_ids')->nullable();   // agent user ids, first to last
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_schedule_patterns');
    }
};
