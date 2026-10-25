<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-478 §19.4 — Feature wording & videos (Dev Settings, owner-only).
 * Spec: .ai/specs/agency-feature-rollout.md §19.4.
 *
 * Platform-wide (no agency): the wording the rollout pop-ups show for a feature. A row exists only for a
 * feature a dev has edited; no row = the defaults (registry `explain` + config/feature-rollout-copy.php).
 * "Go back to the default wording" soft-deletes the row; Restore brings the edit back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('feature_rollout_feature_copy', function (Blueprint $table) {
            $table->id();
            $table->string('feature_key', 100)->unique();
            $table->string('summary', 500)->nullable();
            $table->text('description')->nullable();
            $table->string('video_url', 500)->nullable();
            $table->json('links')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('feature_rollout_feature_copy');
    }
};
