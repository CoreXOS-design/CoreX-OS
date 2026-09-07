<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-385 — `require_identity_before_send`: blocks Fill & Review from
 * sending a document if any signing party has no ID/passport number
 * (document first, contact fallback). Default true — Johan: "no id is a
 * massive problem... we have to gate against it properly."
 *
 * AT-332 — `strict_reauthorisation_binding`: after a recipient amends a
 * document, re-authorisation must come from the SAME user who authorised
 * the original, not any other qualifying agent. Default true — Johan:
 * "re-auth only allowed by original auth party."
 *
 * Both on the existing per-agency table (one row per agency, same
 * resolver pattern as async_completion_enabled — see
 * App\Models\Docuperfect\EsignSettings::forAgency()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
            if (!Schema::hasColumn('docuperfect_esign_settings', 'require_identity_before_send')) {
                $table->boolean('require_identity_before_send')->default(true)->after('finalization_stuck_threshold_minutes');
            }
            if (!Schema::hasColumn('docuperfect_esign_settings', 'strict_reauthorisation_binding')) {
                $table->boolean('strict_reauthorisation_binding')->default(true)->after('require_identity_before_send');
            }
        });
    }

    public function down(): void
    {
        Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
            foreach (['strict_reauthorisation_binding', 'require_identity_before_send'] as $col) {
                if (Schema::hasColumn('docuperfect_esign_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
