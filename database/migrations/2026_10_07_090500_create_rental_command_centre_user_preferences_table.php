<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-command-centre.md §10 — the needs-action queue's own
 * collapse/expand toggle, remembered per user, server-side (same reason
 * as rental_inspection_screen_preferences: a laptop-width preference must
 * follow the agent to a narrower screen, so localStorage alone is not
 * enough). Same shape, deliberately: one row per user, a JSON map of
 * preference key => value. Kept as its own table rather than sharing
 * rental_inspection_screen_preferences' rows — different screen, different
 * key space, same reasoning that table's own migration already states for
 * not sharing with rental_review_panel_preferences.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_command_centre_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('preference_state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_command_centre_user_preferences');
    }
};
