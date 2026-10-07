<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core Matches — which Buyer Pipeline statuses take a buyer OFF Core Matches
 * (Johan, 2026-10-07: Won/Lost buyers must not show matches; the statuses are an
 * agency setting, default Won + Lost, never hardcoded).
 *
 * JSON array of pipeline status slugs (new/warm/cold/lost/won). Sibling of
 * core_matches_allowed_statuses on the same table. NULL = "use the code default"
 * (AgencyContactSettings::DEFAULT_CORE_MATCHES_EXCLUDED_BUYER_STATES), so every
 * existing agency resolves correctly with no backfill and stays out of
 * forAgency()'s $defaults. An explicitly stored empty array means "exclude no one".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->json('core_matches_excluded_buyer_states')->nullable()->after('core_matches_allowed_statuses');
        });

        // Append-only audit: one row per save that CHANGED the setting (same shape as
        // viewing_pack_cover_audit). Who changed it, from what, to what, when.
        Schema::create('core_match_buyer_state_audit', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('changed_by_user_id')->nullable();
            $table->json('old_values');
            $table->json('new_values');
            $table->timestamp('changed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('core_match_buyer_state_audit');
        Schema::table('agency_contact_settings', function (Blueprint $table) {
            $table->dropColumn('core_matches_excluded_buyer_states');
        });
    }
};
