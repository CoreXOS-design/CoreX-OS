<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-149 — bridge the WAHA server-side session to the existing per-agent device
 * row so the WhatsApp webhook adapter can attribute an incoming WAHA message to
 * the right agency + agent (which the WaArchiveIngestor requires).
 *
 * A communication_wa_devices row now represents EITHER:
 *   - a browser-extension capture device  → device_token set (SHA-256), no session
 *   - a WAHA server-session link          → waha_session set (WAHA session name),
 *                                            no device_token
 *
 * So device_token becomes nullable (a session link has no per-device bearer
 * token; the webhook is authenticated by the WAHA HMAC/secret, not a device
 * token). waha_session is nullable + unique — one WAHA session maps to exactly
 * one device row; multiple NULLs are allowed (all the extension devices).
 * Additive, no data rewrite, no hard deletes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('communication_wa_devices', function (Blueprint $table) {
            // A server-session link carries no bearer token.
            $table->char('device_token', 64)->nullable()->change();

            $table->string('waha_session', 191)->nullable()->after('wa_number');
            $table->unique('waha_session', 'comm_wa_session_uq');
        });
    }

    public function down(): void
    {
        Schema::table('communication_wa_devices', function (Blueprint $table) {
            $table->dropUnique('comm_wa_session_uq');
            $table->dropColumn('waha_session');
            // Restore NOT NULL (backfill any nulls first so the change can't fail).
            $table->char('device_token', 64)->nullable(false)->default('')->change();
        });
    }
};
