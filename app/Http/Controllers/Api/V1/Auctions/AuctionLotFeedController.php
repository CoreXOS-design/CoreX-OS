<?php

namespace App\Http\Controllers\Api\V1\Auctions;

use App\Http\Controllers\Controller;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Services\Auctions\BidService;
use Illuminate\Http\JsonResponse;

/**
 * AT-432 Phase 4 — .ai/specs/auctions.md §11.2, §11.4, §14.3. THE one
 * catalogued bid-feed route (CLAUDE.md Non-negotiable #7 — "no hidden JSON
 * endpoint is created for the bid feed"), polled by the hybrid Sale Room
 * today and, in Phase 5, by the public lot page — one route, two
 * consumers, never a second implementation. Route-model binding on
 * AuctionLot (BelongsToAgency) enforces agency scoping before this method
 * ever runs; direct-URL access to another agency's lot 404s.
 *
 * Deliberately excludes reserve_price, bidder contact details, and
 * anything else `reserve_visibility`/branch-restriction would otherwise
 * gate — this endpoint returns only what is always safe to poll,
 * regardless of who or what eventually consumes it.
 */
class AuctionLotFeedController extends Controller
{
    public function show(AuctionLot $lot): JsonResponse
    {
        $bidService = new BidService();
        $current = $bidService->currentHighBid($lot);

        return response()->json([
            'lot_id' => $lot->id,
            'lot_number' => $lot->lot_number,
            'status' => $lot->status,
            'online_closes_at' => $lot->online_closes_at?->toIso8601String(),
            'current_bid' => $current ? [
                'amount' => (float) $current->amount,
                'channel' => $current->channel,
                'is_proxy' => (bool) $current->is_proxy,
                'bidder_paddle_number' => $current->bidder?->paddle_number,
                'placed_at' => $current->placed_at?->toIso8601String(),
            ] : null,
            'suggested_next_bid' => $bidService->suggestedNextBid($lot),
            'bid_count' => $bidService->bidCount($lot),
            'distinct_bidder_count' => $bidService->distinctBidderCount($lot),
            // Last 5, newest first — enough for the Sale Room's live history
            // panel to reflect an online/proxy bid the instant it lands,
            // without a second, separate "bid list" route (§14.3: one
            // catalogued feed route, not a hidden endpoint per consumer).
            'recent_bids' => AuctionBid::where('auction_lot_id', $lot->id)
                ->whereNull('retracted_at')
                ->with('bidder:id,paddle_number')
                ->orderByDesc('placed_at')
                ->limit(5)
                ->get()
                ->map(fn (AuctionBid $b) => [
                    'id' => $b->id,
                    'amount' => (float) $b->amount,
                    'channel' => $b->channel,
                    'is_proxy' => (bool) $b->is_proxy,
                    'bidder_paddle_number' => $b->bidder?->paddle_number,
                ]),
            'server_time' => now()->toIso8601String(),
        ]);
    }
}
