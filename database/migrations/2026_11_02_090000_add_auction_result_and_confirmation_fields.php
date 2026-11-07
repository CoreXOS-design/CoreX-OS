<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auctions v2 phase 4 (.ai/specs/auctions.md §8.6): what happens after the hammer.
 *  - auction_lots.deal_id        the Deal opened when the lot sold
 *  - auction_lots.confirmed_at   when the seller confirmed a below-reserve sale
 *  - agency_auction_settings.confirmation_days   how long a seller has to confirm or decline (default 3)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_lots', function (Blueprint $t) {
            $t->unsignedBigInteger('deal_id')->nullable()->after('winning_bid_id');
            $t->dateTime('confirmed_at')->nullable()->after('confirmation_deadline');
            $t->index('deal_id', 'au2_lots_deal_ix');
        });

        Schema::table('agency_auction_settings', function (Blueprint $t) {
            $t->unsignedSmallInteger('confirmation_days')->nullable()->after('outbid_emails');
        });
    }

    public function down(): void
    {
        Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->dropColumn('confirmation_days'));
        Schema::table('auction_lots', function (Blueprint $t) {
            $t->dropIndex('au2_lots_deal_ix');
            $t->dropColumn(['deal_id', 'confirmed_at']);
        });
    }
};
