<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Feature 1 (bond attorney, enforce-at-grant) — the deal names its BOND ATTORNEY
 * (firm + working contact), mirroring the transferring-attorney / bond-originator /
 * external-agency pairs. Both nullable; captured on Email Parties AFTER the bond is
 * granted (the bank appoints it post-grant), never at deal setup. The party-first
 * distribution resolver (Dr2DistributionComposer) reads them for Email Parties + doc copies.
 * NB: no specialty-enum migration — 'bond_attorney' already exists in the
 * agency_service_providers.specialty enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('bond_attorney_provider_id')->nullable()->after('external_agency_contact_id');
            $table->unsignedBigInteger('bond_attorney_contact_id')->nullable()->after('bond_attorney_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['bond_attorney_provider_id', 'bond_attorney_contact_id']);
        });
    }
};
