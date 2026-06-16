<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-46 — per-template toggle for the live-demand tracking link.
 *
 * Consent-request templates (e.g. HFC's WhatsApp opt-in ask) carry no
 * live-demand tracking link. Default TRUE preserves existing behaviour —
 * every existing template still requires {tracking_link}. The opt-out (STOP)
 * clause stays mandatory for ALL templates regardless of this flag.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('seller_outreach_templates', function (Blueprint $table) {
            $table->boolean('include_tracking_link')->default(true)->after('is_default_for_channel');
        });
    }

    public function down(): void
    {
        Schema::table('seller_outreach_templates', function (Blueprint $table) {
            $table->dropColumn('include_tracking_link');
        });
    }
};
