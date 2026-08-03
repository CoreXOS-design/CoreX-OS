<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Remove background" — AI segmentation API architecture (ad-manager.md §15.2),
 * superseding the client-side flood-fill colour heuristic (§15.1 rounds 1-6).
 *
 * `ad_bg_removal_api_enabled` is the per-agency kill switch: default ON, so a
 * bad cutout result or a billing problem is one toggle away from OFF without a
 * deploy. The API key itself is never per-agency and never touches the
 * database (STANDARDS.md "API Keys and Credentials Live in .env Only") — every
 * agency's photos are processed through ONE system-wide Photoroom/remove.bg
 * account, so this column controls whether THIS agency's uploads are sent to
 * it at all, not which account they're billed to.
 *
 * Deliberately NOT auto-added to the Setup Wizard in this round — the API key
 * is not populated yet (QA2-only build, awaiting Johan's provider signup), so
 * there is nothing live for an agency to be walked through. Revisit once a
 * real key is in Staging/live (non-negotiable #10a — recorded, not silently
 * skipped; see ad-manager.md §15.2's "Deliberately NOT in the wizard yet").
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agencies', 'ad_bg_removal_api_enabled')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->boolean('ad_bg_removal_api_enabled')->default(true)->after('ad_bg_removal_flood_fill_drift_cap_px');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agencies', 'ad_bg_removal_api_enabled')) {
            return;
        }

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn(['ad_bg_removal_api_enabled']);
        });
    }
};
