<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agency Public API — Phase 1a.
 *
 * Per-agency API credentials for external agency websites. ONE public API
 * surface, MANY keys (one per website). Each key authenticates a website,
 * carries its own scopes + webhook target, and is the label used for the
 * website's Syndication Portal. The full secret is shown once at creation
 * and only its hash is stored.
 *
 * Spec: .ai/specs/agency-public-api.md §3.1, §3.5
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_api_keys')) {
            return;
        }

        Schema::create('agency_api_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();

            // The website name (e.g. "Home Finders Coastal"). Doubles as the
            // Syndication Portal label in settings + each property's panel.
            $table->string('name');

            // Non-secret public identifier shown in the UI (e.g. cx_live_a1b2c3).
            $table->string('key_prefix', 24)->unique();

            // sha256 hash of the full secret. Plaintext never stored.
            $table->string('secret_hash');

            // Granted scopes, e.g. ["listings:read","agents:read","agency:read","webhooks:receive"].
            $table->json('scopes')->nullable();

            // Webhook target + signing secret (encrypted via model cast).
            $table->string('webhook_url')->nullable();
            $table->text('webhook_secret')->nullable();

            $table->unsignedInteger('rate_limit_per_min')->default(120);

            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_api_keys');
    }
};
