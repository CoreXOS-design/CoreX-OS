<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds two agency-scoped WhatsApp launch-mode settings:
 *
 *   - whatsapp_launch_mode_agent  (Compose pitch → Send button)
 *   - whatsapp_launch_mode_seller (Public landing → Reply on WhatsApp button)
 *
 * Each is 'whatsapp_app' (direct app deeplink — no intermediate page) or
 * 'whatsapp_web' (wa.me universal-fallback URL — works regardless of install).
 *
 * Default is 'whatsapp_web' for safety — existing agencies see no behaviour
 * change until they opt into 'whatsapp_app' via the Company Settings UI.
 *
 * Spec: hotfix prompt 2026-05-14, builds on .ai/specs/seller-outreach-spec.md S6.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('whatsapp_launch_mode_agent', 20)
                ->default('whatsapp_web')
                ->after('popi_url');
            $table->string('whatsapp_launch_mode_seller', 20)
                ->default('whatsapp_web')
                ->after('whatsapp_launch_mode_agent');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['whatsapp_launch_mode_agent', 'whatsapp_launch_mode_seller']);
        });
    }
};
