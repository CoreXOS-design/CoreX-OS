<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §5.5. The bid log. Append-only in
 * spirit: a bid is never updated or deleted — a retraction writes
 * retracted_at/by/reason and leaves the row intact (§11.1). This table IS
 * the auction register for CPA purposes (§18).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auction_bids', function (Blueprint $table) {
            $table->id();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_lot_id')->constrained()->cascadeOnDelete();
            $table->foreignId('auction_bidder_id')->constrained()->cascadeOnDelete();

            $table->decimal('amount', 15, 2);
            $table->string('channel', 20); // in_room|online|phone|absentee|proxy
            $table->dateTime('placed_at', 3);
            $table->boolean('is_proxy')->default(false);
            $table->decimal('proxy_max', 15, 2)->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_winning')->default(false);

            $table->dateTime('retracted_at')->nullable();
            $table->foreignId('retracted_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('retracted_reason', 500)->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['auction_lot_id', 'placed_at'], 'auction_bids_lot_placed_idx');
            $table->index(['auction_lot_id', 'amount'], 'auction_bids_lot_amount_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auction_bids');
    }
};
