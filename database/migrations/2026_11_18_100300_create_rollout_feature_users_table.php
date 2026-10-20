<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 Part 5 — one row per person per feature their agency's gradual rollout switched on
 * (spec agency-feature-rollout.md §12.5, §12.6, §12.8):
 *   first_opened_at    — the first time they opened it (usage, the pause suggestion, the owner overview)
 *   tour_nudged_at     — when they were reminded to take its guided tour (once)
 *   early_requested_at — when they asked for it early from the "New and coming" page (once)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rollout_feature_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('feature_key', 64);
            $table->timestamp('first_opened_at')->nullable();
            $table->timestamp('tour_nudged_at')->nullable();
            $table->timestamp('early_requested_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['agency_id', 'user_id', 'feature_key']);
            $table->index(['agency_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rollout_feature_users');
    }
};
