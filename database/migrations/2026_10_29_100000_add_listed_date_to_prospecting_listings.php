<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-454 (Road to Sold) piece 2 — a REAL listed date for MIC listings.
 *
 * prospecting_listings.first_seen_at is the moment CoreX first CAPTURED the advert, not when the
 * portal says it went live (31k rows share an August bulk-capture clock), and
 * first_seen_email_date has never been written by any code. "New on market" / "long on market"
 * need the portal's own date, so it is stored SEPARATELY and first_seen_at is left untouched.
 *
 * listed_date_source: 'portal' = the advert's own date as captured; 'agent' = corrected by hand
 * (with a reason, see prospecting_listing_date_corrections). A re-capture never overwrites 'agent'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->date('listed_date')->nullable()->after('first_seen_email_date');
            $table->string('listed_date_source', 12)->nullable()->after('listed_date');
            $table->index(['agency_id', 'listed_date'], 'prospecting_listings_agency_listed_date_idx');
        });

        Schema::create('prospecting_listing_date_corrections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id');
            $table->unsignedBigInteger('prospecting_listing_id');
            $table->unsignedBigInteger('user_id');
            $table->date('old_date')->nullable();
            $table->date('new_date');
            $table->string('reason', 2000);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['agency_id', 'prospecting_listing_id'], 'pl_date_corrections_agency_listing_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospecting_listing_date_corrections');
        Schema::table('prospecting_listings', function (Blueprint $table) {
            $table->dropIndex('prospecting_listings_agency_listed_date_idx');
            $table->dropColumn(['listed_date', 'listed_date_source']);
        });
    }
};
