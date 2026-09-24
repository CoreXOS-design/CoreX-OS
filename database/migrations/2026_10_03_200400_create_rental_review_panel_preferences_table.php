<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-430 §3.1 — "Open/collapsed state is remembered per user per section."
 * One row per user, a JSON map of panel-key => bool (e.g. {"finances":
 * true, "checklist": true, "checklist_section_3": false}). Missing key =
 * default for that panel (finances/checklist open, per-section collapsed).
 * No agency_id — same convention as calendar_user_preferences: scoped by
 * user_id alone, which is already agency-bound via the user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_review_panel_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('panel_state')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_review_panel_preferences');
    }
};
