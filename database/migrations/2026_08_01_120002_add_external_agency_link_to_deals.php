<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Note 2 (external agency) — the deal names its EXTERNAL AGENCY (firm + working contact),
 * mirroring the transferring-attorney and bond-originator pairs. Both nullable; the party-first
 * distribution resolver (Dr2DistributionComposer) reads them for Email Parties + doc copies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('external_agency_provider_id')->nullable()->after('bond_originator_contact_id');
            $table->unsignedBigInteger('external_agency_contact_id')->nullable()->after('external_agency_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['external_agency_provider_id', 'external_agency_contact_id']);
        });
    }
};
