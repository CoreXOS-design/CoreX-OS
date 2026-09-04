<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-385 / AT-332 — WhatsApp resend for e-sign signing links (Johan signed
 * off: WhatsApp is a SECONDARY, manual, agent-clicked send method — email
 * stays the automatic primary, nothing in the routing/advance flow waits
 * on it). `whatsapp_resend_enabled` — per-agency opt-out for the "Send via
 * WhatsApp" button. Default true per Johan's explicit instruction.
 *
 * Same existing per-agency table (one row per agency, same resolver
 * pattern as async_completion_enabled — see
 * App\Models\Docuperfect\EsignSettings::forAgency()). Idempotent guard
 * matches the sibling AT-385/AT-332 identity-gate migration landing on
 * this same table concurrently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('docuperfect_esign_settings', 'whatsapp_resend_enabled')) {
                $table->boolean('whatsapp_resend_enabled')->default(true)->after('finalization_stuck_threshold_minutes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
            if (Schema::hasColumn('docuperfect_esign_settings', 'whatsapp_resend_enabled')) {
                $table->dropColumn('whatsapp_resend_enabled');
            }
        });
    }
};
