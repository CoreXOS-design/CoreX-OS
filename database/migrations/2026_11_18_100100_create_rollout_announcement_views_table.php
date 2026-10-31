<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 Part 3 — who has seen which gradual-rollout release pop-up.
 * Spec: .ai/specs/agency-feature-rollout.md §4, §5.4.
 *
 * One row per user per released wave (kind 'release'). The admin access review is recorded on the
 * wave itself (agency_rollout_waves.access_reviewed_at), so one admin reviewing clears it for all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollout_announcement_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('wave_id')->constrained('agency_rollout_waves')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 16)->default('release'); // release | admin_preview
            $table->timestamp('seen_at');
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['wave_id', 'user_id', 'kind']);
            $table->index(['agency_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollout_announcement_views');
    }
};
