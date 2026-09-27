<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use Illuminate\Support\Facades\DB;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §11.1. In-room bid capture for the
 * Sale Room console. Phase 4's online concurrency-locking machinery
 * (SELECT ... FOR UPDATE, §11.2) is deliberately NOT built here — Phase 3
 * is a single clerk typing on the floor, not concurrent remote bidders; the
 * phase boundary in §21 places true concurrency locking under Phase 4.
 *
 * §11.3: "the auctioneer may always accept an off-increment bid on the
 * floor — the bands govern the online engine and *suggest* on the floor;
 * they never block the person holding the gavel." This service therefore
 * validates only that a bid exceeds the current high bid — never the
 * increment band.
 */
class BidService
{
    public function currentHighBid(AuctionLot $lot): ?AuctionBid
    {
        return AuctionBid::where('auction_lot_id', $lot->id)
            ->whereNull('retracted_at')
            ->orderByDesc('amount')
            ->orderByDesc('placed_at')
            ->first();
    }

    /**
     * §11.3 — the suggested next increment on top of the current high bid,
     * or the opening bid if there is none yet. Returns null when there is
     * neither a current bid nor an opening_bid to suggest from — the lot
     * genuinely has no sane starting figure, and the caller must require
     * an explicit amount rather than defaulting to 0 (a 0 "suggestion"
     * would always fail the > current-high-bid check, or worse, silently
     * succeed as a real bid if it ever didn't).
     */
    public function suggestedNextBid(AuctionLot $lot): ?float
    {
        $current = $this->currentHighBid($lot);

        if ($current) {
            $increment = $lot->bid_increment !== null
                ? (float) $lot->bid_increment
                : $this->incrementForAmount((int) $lot->agency_id, (float) $current->amount);

            return (float) $current->amount + $increment;
        }

        return $lot->opening_bid !== null ? (float) $lot->opening_bid : null;
    }

    private function incrementForAmount(int $agencyId, float $amount): float
    {
        foreach (AgencyAuctionSettings::onlineBidIncrementBandsFor($agencyId) as $band) {
            $to = $band['to'] ?? null;
            if ($amount >= (float) $band['from'] && ($to === null || $amount <= (float) $to)) {
                return (float) $band['increment'];
            }
        }

        return 0.0;
    }

    /**
     * Record a bid. Validates: the lot is actually biddable, the bidder may
     * hold a paddle (§10.2 — server-side, never trust the screen), and the
     * amount exceeds the current high bid.
     *
     * @throws \RuntimeException on any failed validation
     */
    public function placeBid(
        AuctionLot $lot,
        AuctionBidder $bidder,
        float $amount,
        string $channel = 'in_room',
        ?int $recordedById = null,
    ): AuctionBid {
        if (! in_array($lot->status, [AuctionLot::STATUS_OPEN_FOR_BIDS, AuctionLot::STATUS_UNDER_THE_HAMMER], true)) {
            throw new \RuntimeException("Lot #{$lot->id} is not open for bidding (status: {$lot->status}).");
        }

        if (! (new BidderGateService())->canBid($bidder)) {
            throw new \RuntimeException("Bidder #{$bidder->id} may not bid — the paddle gate is not satisfied.");
        }

        return DB::transaction(function () use ($lot, $bidder, $amount, $channel, $recordedById) {
            $current = $this->currentHighBid($lot->refresh());
            $currentAmount = $current ? (float) $current->amount : 0.0;

            if ($amount <= $currentAmount) {
                throw new \RuntimeException("Bid of {$amount} does not exceed the current high bid of {$currentAmount}.");
            }
            if ($lot->opening_bid !== null && ! $current && $amount < (float) $lot->opening_bid) {
                throw new \RuntimeException("Bid of {$amount} is below the opening bid of {$lot->opening_bid}.");
            }

            return AuctionBid::create([
                'agency_id' => $lot->agency_id,
                'auction_id' => $lot->auction_id,
                'auction_lot_id' => $lot->id,
                'auction_bidder_id' => $bidder->id,
                'amount' => $amount,
                'channel' => $channel,
                'placed_at' => now(),
                'recorded_by_id' => $recordedById,
            ]);
        });
    }

    /** §11.1 — "Backspace-equivalent retracts the last bid... always with a reason, always audited." */
    public function retractLastBid(AuctionLot $lot, ?int $actorId = null, ?string $reason = null): AuctionBid
    {
        $agencyId = (int) $lot->agency_id;
        if (! AgencyAuctionSettings::bidRetractionAllowedFor($agencyId)) {
            throw new \RuntimeException('Bid retraction is not enabled for this agency.');
        }

        $last = $this->currentHighBid($lot);
        if (! $last) {
            throw new \RuntimeException("Lot #{$lot->id} has no active bid to retract.");
        }

        $last->update(['retracted_at' => now(), 'retracted_by_id' => $actorId, 'retracted_reason' => $reason]);

        return $last;
    }

    public function bidCount(AuctionLot $lot): int
    {
        return AuctionBid::where('auction_lot_id', $lot->id)->whereNull('retracted_at')->count();
    }

    public function distinctBidderCount(AuctionLot $lot): int
    {
        return AuctionBid::where('auction_lot_id', $lot->id)->whereNull('retracted_at')->distinct('auction_bidder_id')->count('auction_bidder_id');
    }
}
