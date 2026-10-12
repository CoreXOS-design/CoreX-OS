<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 4 — .ai/specs/auctions.md §11.2. The per-lot online bidding
 * close time — defaults from the agency's online_auto_extend_minutes
 * pushing this out on a late bid ("auto-extend... unlimited extensions").
 * Nullable and distinct from the parent auction's own ends_at: §11.2
 * allows staggered per-lot closing, not just one synchronized close for
 * every lot in the auction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dateTime('online_closes_at')->nullable()->after('bid_increment');
        });
    }

    public function down(): void
    {
        Schema::table('auction_lots', function (Blueprint $table) {
            $table->dropColumn('online_closes_at');
        });
    }
};
