<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * .ai/specs/rental-inspections.md §27.7 — the recording screen's own
 * "photos visible" / "problem filter" controls, remembered per user,
 * server-side (Johan's ruling — a filter set on a laptop must follow the
 * agent to the phone they inspect with, so localStorage is not enough).
 * Same shape as rental_review_panel_preferences: one row per user, a JSON
 * map of preference key => value. No agency_id — same convention, scoped
 * by user_id alone, which is already agency-bound via the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_inspection_screen_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('preference_state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_inspection_screen_preferences');
    }
};
