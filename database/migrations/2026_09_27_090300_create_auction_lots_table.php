<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 1 — .ai/specs/auctions.md §5.3. A property's place in an
 * auction.
 *
 * `winning_bid_id` / `winning_bidder_id` are plain unsigned columns with NO
 * foreign-key constraint here — `auction_bids` and `auction_bidders` are
 * Phase 2/3 tables (§21) that do not exist yet. Both stay nullable through
 * Phase 1 (an externally-run auction's result is recorded manually via
 * AuctionLotStatusService, with no bidder attribution until Phase 2 lands);
 * the FK constraints are added by the migration that creates those tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_lots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('property_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('lot_number');
            $table->decimal('reserve_price', 15, 2)->nullable();
            $table->decimal('guide_price_min', 15, 2)->nullable();
            $table->decimal('guide_price_max', 15, 2)->nullable();
            $table->decimal('opening_bid', 15, 2)->nullable();
            $table->decimal('bid_increment', 12, 2)->nullable();
            $table->decimal('buyers_premium_percent', 5, 2)->nullable();
            $table->decimal('sellers_commission_percent', 5, 2)->nullable();
            $table->decimal('deposit_percent', 5, 2)->nullable();
            $table->decimal('deposit_amount', 12, 2)->nullable();

            $table->string('status', 30)->default('draft'); // §6.2
            $table->decimal('hammer_price', 15, 2)->nullable();
            $table->dateTime('hammer_at')->nullable();
            $table->unsignedBigInteger('winning_bid_id')->nullable();
            $table->unsignedBigInteger('winning_bidder_id')->nullable();
            $table->boolean('reserve_met')->nullable();
            $table->dateTime('confirmation_deadline')->nullable();
            $table->dateTime('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('deal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('withdrawn_reason', 500)->nullable();
            $table->dateTime('passed_in_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['auction_id', 'lot_number'], 'auction_lots_auction_lot_number_unique');
            $table->index(['agency_id', 'status'], 'auction_lots_agency_status_idx');
            // Application-enforced (not a DB unique) that (auction_id, property_id) is
            // unique among non-archived rows — a plain DB unique would collide with a
            // withdrawn-then-relisted lot's soft-deleted predecessor.
            $table->index(['auction_id', 'property_id'], 'auction_lots_auction_property_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_lots');
    }
};
