<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionBidder;

/**
 * AT-432 Phase 2 — .ai/specs/auctions.md §4.4 `paddle_number_mode`. Issues
 * a paddle number on approval (§10.2), never at registration — a
 * registration is provisional until staff approve it.
 */
class PaddleNumberService
{
    /**
     * Sequential: the next unused number for this auction, as a
     * zero-padded string ("001", "002", …) so paddles sort and display
     * consistently regardless of how many digits the count reaches.
     * Manual: the caller supplies $manualNumber; this method still
     * validates it is not already taken within the auction.
     */
    public function issueFor(AuctionBidder $bidder, ?string $manualNumber = null): string
    {
        $mode = AgencyAuctionSettings::paddleNumberModeFor((int) $bidder->agency_id);

        if ($mode === 'manual') {
            $number = trim((string) $manualNumber);
            if ($number === '') {
                throw new \InvalidArgumentException('A manual paddle number is required in manual mode.');
            }
        } else {
            $number = $this->nextSequential($bidder->auction);
        }

        if ($this->isTaken($bidder->auction_id, $number, $bidder->id)) {
            throw new \RuntimeException("Paddle number '{$number}' is already in use for this auction.");
        }

        return $number;
    }

    private function nextSequential(Auction $auction): string
    {
        $count = AuctionBidder::withoutGlobalScopes()
            ->where('auction_id', $auction->id)
            ->whereNotNull('paddle_number')
            ->count();

        return str_pad((string) ($count + 1), 3, '0', STR_PAD_LEFT);
    }

    private function isTaken(int $auctionId, string $number, ?int $exceptBidderId = null): bool
    {
        return AuctionBidder::withoutGlobalScopes()
            ->where('auction_id', $auctionId)
            ->where('paddle_number', $number)
            ->when($exceptBidderId, fn ($q) => $q->where('id', '!=', $exceptBidderId))
            ->exists();
    }
}
