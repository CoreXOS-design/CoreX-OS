<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-385 / AT-332 — Johan, verbatim: "No, this is not settings but fixes we
 * are building." Neither the identity-required-to-send gate nor the strict
 * re-authorisation binding is an agency preference — both are unconditional
 * correct behaviour, never agency-configurable. A settings toggle here was
 * a footgun: the one agency that turned it off is the one that ends up with
 * an unidentified signer on a mandate.
 *
 * Follow-up migration rather than editing
 * 2026_09_04_124100_add_esign_gate_settings_to_docuperfect_esign_settings —
 * that migration may already have run somewhere; history is not rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('docuperfect_esign_settings', function (Blueprint $table) {
            foreach (['require_identity_before_send', 'strict_reauthorisation_binding'] as $col) {
                if (Schema::hasColumn('docuperfect_esign_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }

    public function down(): void
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
};
