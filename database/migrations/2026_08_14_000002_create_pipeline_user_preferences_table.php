<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pipeline Dashboard Phase 1 — per-agent default view for the deal pipeline (timeline | list).
 * Mirrors the existing calendar_user_preferences pattern. One row per user. Spec §3.4.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('pipeline_user_preferences')) {
            return;
        }
        Schema::create('pipeline_user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('default_view', 20)->default('timeline'); // timeline | list
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_user_preferences');
    }
};
