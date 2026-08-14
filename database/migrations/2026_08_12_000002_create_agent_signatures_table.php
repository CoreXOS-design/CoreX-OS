<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Saved signature/initial + signing PIN foundation. One row per agent.
 *
 * signature_image / initial_image are ENCRYPTED at rest (Laravel 'encrypted'
 * cast → AES-256 via APP_KEY) — they hold small PNG data-URIs. signing_pin is a
 * bcrypt HASH, separate from the login password. The genuinely-logged-in agent
 * is the only one who can ever read or place these — a switch-user / impersonated
 * session is blocked at the service layer (see AgentSignatureService).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('agent_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id')->index();
            $table->longText('signature_image')->nullable();   // encrypted at rest
            $table->longText('initial_image')->nullable();     // encrypted at rest
            $table->string('signing_pin')->nullable();         // bcrypt hash (NOT the login password)
            $table->timestamp('pin_set_at')->nullable();
            $table->timestamp('images_updated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_signatures');
    }
};
