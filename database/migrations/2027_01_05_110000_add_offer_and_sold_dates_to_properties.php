<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * MIC Work tab, step 5.4 (.ai/specs/mic-work-tab.md sections 7, 8 and 11.4, AT-524): the moment a property went Under Offer and the
 * moment it went Sold, stamped at every status change from now on (PropertyOfferSoldDates, called from PropertyObserver::saved).
 * Before this the only trustworthy sale-side timestamps were audit events; `sold_stock.sale_date` was the 17 Sep backfill day on 434 of 519 rows.
 * NULL = not known (never guessed). The columns describe the CURRENT state: an offer that falls through clears `under_offer_at`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->timestamp('under_offer_at')->nullable()->after('portal_sold_until');
            $table->timestamp('sold_at')->nullable()->after('under_offer_at');
            $table->index(['agency_id', 'sold_at'], 'properties_agency_sold_at_idx');
        });
    }

    public function down(): void
    {
        Schema::table('properties', function (Blueprint $table) {
            $table->dropIndex('properties_agency_sold_at_idx');
            $table->dropColumn(['under_offer_at', 'sold_at']);
        });
    }
};
