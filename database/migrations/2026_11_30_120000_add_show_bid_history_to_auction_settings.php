<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Auctions v2 (.ai/specs/auctions.md §20): the agency can hide the "Bidding activity" list on the public lot page. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('agency_auction_settings') && ! Schema::hasColumn('agency_auction_settings', 'show_bid_history')) {
            Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->boolean('show_bid_history')->nullable());   // null = on
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('agency_auction_settings', 'show_bid_history')) {
            Schema::table('agency_auction_settings', fn (Blueprint $t) => $t->dropColumn('show_bid_history'));
        }
    }
};
