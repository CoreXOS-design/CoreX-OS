<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-P24 — two-tier listing status (base status + sub-label banner).
 *
 * P24/Propcon model a listing's lifecycle as a BASE status (For Sale, Sold,
 * Under Offer, Withdrawn, Expired, Cancelled, Let Out, …) plus an optional
 * SUB-LABEL banner on top of an on-market base status — e.g. For Sale +
 * "Reduced Price", For Sale + "Pending", For Sale + "Back on Market".
 *
 * CoreX historically stored these flat in a single `status` column, which
 * conflated base statuses with sub-labels (Reduced Price / Pending / Back on
 * Market / Raised Price were stored AS the status). This column restores the
 * correct two-tier model: `status` holds the base status, `status_label` holds
 * the optional banner. Both feed Property24ListingMapper::getP24Status() so the
 * P24 lifecycle state round-trips exactly. Null = plain base status (no banner).
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->string('status_label')->nullable()->after('status')
                  ->comment('Optional sub-label banner on a base status (e.g. "Reduced Price", "Pending"). Two-tier P24/Propcon model — see AT-P24.');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropColumn('status_label');
        });
    }
};
