<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 3 — the last deferred FK from 2026_09_27_090300's docblock:
 * auction_lots.winning_bid_id now that auction_bids exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->foreign('winning_bid_id')->references('id')->on('auction_bids')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropForeign(['winning_bid_id']);
        });
    }
};
