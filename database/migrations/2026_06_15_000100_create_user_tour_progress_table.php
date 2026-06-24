<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interactive help-tour engine — per-user progress.
 *
 * One row per (user, tour). The tour is identified by its stable string key
 * (e.g. "contact-capture"), NOT a FK, so renaming/removing a tour definition
 * never orphans data and the registry stays the single source of truth.
 *
 * This table is intentionally personal UI state (a server-side replacement for
 * what would otherwise be localStorage) — it is keyed by user_id only and is
 * not tenant-owned, so it carries no agency_id. Auto-show is suppressed once
 * either completed_at OR dismissed_at is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tour_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('tour_key', 100);
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'tour_key']);
            $table->index('tour_key');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tour_progress');
    }
};
