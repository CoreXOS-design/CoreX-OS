<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 2 — companion to 2026_09_27_090300's own docblock: adds the
 * FK constraint on auction_lots.winning_bidder_id now that auction_bidders
 * exists. winning_bid_id stays unconstrained until Phase 3 creates
 * auction_bids.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->foreign('winning_bidder_id')->references('id')->on('auction_bidders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropForeign(['winning_bidder_id']);
        });
    }
};
