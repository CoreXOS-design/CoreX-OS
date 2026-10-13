<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 — Road to Sold has a track (sales now, rentals later). The market-pool selections read
 * prospecting_listings, which until now never said whether an advert was for sale or to let, so a
 * rentals track would have been offered sale listings. Every existing row is a sale (the MIC capture
 * has only ever read for-sale searches); the rentals capture will write 'rental'.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('prospecting_listings', 'listing_type')) {
            Schema::table('prospecting_listings', function (Blueprint $table) {
                $table->string('listing_type', 10)->default('sale')->after('portal_ref');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('prospecting_listings', 'listing_type')) {
            Schema::table('prospecting_listings', function (Blueprint $table) {
                $table->dropColumn('listing_type');
            });
        }
    }
};
