<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * WhatsApp adapter registration (AT-32, spec §4.5). The Web-capture extension
 * authenticates with device_token (stored as a SHA-256 hash, mirroring the
 * portal-capture per-user token flow).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('communication_wa_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained('agencies', 'id', 'comm_wa_agency_fk')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users', 'id', 'comm_wa_user_fk')->cascadeOnDelete();

            $table->string('wa_number', 50)->nullable();
            $table->char('device_token', 64);          // sha256 hash of the plaintext token
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('active')->default(true);

            $table->timestamps();
            $table->softDeletes();

            $table->unique('device_token', 'comm_wa_token_uq');
            $table->index(['agency_id', 'active'], 'comm_wa_agency_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_wa_devices');
    }
};
