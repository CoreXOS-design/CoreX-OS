<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per agency. Business rules that were env-flags become agency
     * settings here, per CLAUDE.md's standing rule (no hardcoded/env-only
     * business rules). Absence of a row for an agency is a normal, supported
     * state — the model resolver falls back to config()/env in that case
     * (see App\Models\Docuperfect\EsignSettings::forAgency()).
     */
    public function up(): void
    {
        Schema::create('docuperfect_esign_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->unique();
            // Default true — Johan tested this on 2026-08-31 and wants the speed
            // for every agency going forward. An existing agency with no row yet
            // still resolves via the env fallback (see model), not this default,
            // until it saves the settings page once.
            $table->boolean('async_completion_enabled')->default(true);
            // How long a document may sit "completed" without its post-completion
            // work (PDF/filing/emails) finishing before it's treated as stuck and
            // surfaced as a failure (e.g. queue worker not running).
            $table->unsignedSmallInteger('finalization_stuck_threshold_minutes')->default(15);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('docuperfect_esign_settings');
    }
};
