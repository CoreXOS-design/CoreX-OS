<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-228 — the deal names its BOND ORIGINATOR (firm + working contact), mirroring the
 * transferring-attorney pair. Both nullable; the party-first distribution resolver reads them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->unsignedBigInteger('bond_originator_provider_id')->nullable()->after('attorney_contact_id');
            $table->unsignedBigInteger('bond_originator_contact_id')->nullable()->after('bond_originator_provider_id');
        });
    }

    public function down(): void
    {
        Schema::table('deals', function (Blueprint $table) {
            $table->dropColumn(['bond_originator_provider_id', 'bond_originator_contact_id']);
        });
    }
};
