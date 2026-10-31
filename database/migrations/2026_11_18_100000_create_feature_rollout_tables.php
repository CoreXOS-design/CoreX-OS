<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 — Gradual feature rollout for new agencies.
 * Spec: .ai/specs/agency-feature-rollout.md §4.
 *
 *  feature_rollout_waves   — the platform default order (Dev Settings → Feature rollout). Owner-only, no agency.
 *  agency_feature_rollouts — one plan per agency that opted in.
 *  agency_rollout_waves    — the agency's own COPY of the waves (a running plan never moves under it).
 *  agency_rollout_items    — one row per feature the plan is holding back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_rollout_waves', function (Blueprint $table) {
            $table->id();
            $table->string('track', 16)->default('general'); // general | sales | rentals
            $table->unsignedInteger('position')->default(0);
            $table->string('title');
            $table->text('intro_text')->nullable();
            $table->json('feature_keys');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['track', 'position']);
        });

        Schema::create('agency_feature_rollouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->string('status', 16)->default('active'); // active | paused | completed | cancelled
            $table->unsignedTinyInteger('interval_days')->default(2);
            $table->boolean('weekdays_only')->default(true);
            $table->boolean('skip_holidays')->default(true);
            $table->char('holiday_country', 2)->default('ZA');
            $table->time('release_time')->default('07:00:00');
            $table->boolean('side_by_side')->default(false);
            $table->date('started_on');
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique('agency_id');
            $table->index('status');
        });

        Schema::create('agency_rollout_waves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('rollout_id')->constrained('agency_feature_rollouts')->cascadeOnDelete();
            $table->unsignedTinyInteger('lane')->default(1); // 1 = main order; 2 = rentals side by side
            $table->string('track', 16)->default('general');
            $table->unsignedInteger('position')->default(0);
            $table->string('title');
            $table->text('intro_text')->nullable();
            $table->date('scheduled_for')->nullable();
            $table->timestamp('access_reviewed_at')->nullable();
            $table->foreignId('access_reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['rollout_id', 'lane', 'position']);
            $table->index(['agency_id', 'scheduled_for']);
        });

        Schema::create('agency_rollout_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies')->cascadeOnDelete();
            $table->foreignId('rollout_id')->constrained('agency_feature_rollouts')->cascadeOnDelete();
            $table->foreignId('wave_id')->constrained('agency_rollout_waves')->cascadeOnDelete();
            $table->string('feature_key', 64);
            // held | released | released_early | awaiting_admin | dropped
            $table->string('state', 16)->default('held');
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['rollout_id', 'feature_key']);
            $table->index(['agency_id', 'feature_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_rollout_items');
        Schema::dropIfExists('agency_rollout_waves');
        Schema::dropIfExists('agency_feature_rollouts');
        Schema::dropIfExists('feature_rollout_waves');
    }
};
