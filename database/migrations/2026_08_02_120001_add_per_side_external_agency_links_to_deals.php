<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-side external agency (Johan DR2 deal-structure fix) — the External Agency that
 * handled a side is captured PER SIDE in "Sides, Splits & Agents", using the same
 * FIRM + working-contact provider picker as the transferring-attorney and bond-originator
 * fields. Each side names its own external agency (firm + contact), mirroring the
 * {attorney,bond_originator}_provider_id / _contact_id pairs.
 *
 * These SUPERSEDE the single top-level external_agency_provider_id / external_agency_contact_id
 * pair (retired from the capture UI). Those columns are left in place so deals saved under the
 * old build keep surfacing as an emailable party via Dr2DistributionComposer's legacy fallback.
 *
 * All nullable. NO effect on the commission / pool / our-share / split math, which reads only
 * {side}_external / {side}_split_percent / {side}_our_share_percent — never the agency link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('listing_external_agency_provider_id')->nullable()->after('listing_external_agency');
            $table->unsignedBigInteger('listing_external_agency_contact_id')->nullable()->after('listing_external_agency_provider_id');
            $table->unsignedBigInteger('selling_external_agency_provider_id')->nullable()->after('selling_external_agency');
            $table->unsignedBigInteger('selling_external_agency_contact_id')->nullable()->after('selling_external_agency_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn([
                'listing_external_agency_provider_id',
                'listing_external_agency_contact_id',
                'selling_external_agency_provider_id',
                'selling_external_agency_contact_id',
            ]);
        });
    }
};
