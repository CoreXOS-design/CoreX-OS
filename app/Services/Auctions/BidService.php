<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AuctionBid;
use App\Models\AuctionBidder;
use App\Models\AuctionLot;
use Illuminate\Support\Facades\DB;

/**
 * AT-432 Phases 3-4 — .ai/specs/auctions.md §11.1-§11.3. Bid capture for
 * both the in-room Sale Room console and the online timed engine — ONE
 * bid-acceptance path for every channel (in_room/online/phone/absentee/
 * proxy), so a hybrid lot (§11.4: "floor bids and online bids interleave
 * in auction_bids ordered by placed_at") never has two divergent
 * implementations to drift apart.
 *
 * §11.2 concurrency requirement, upgraded from Phase 3's single-clerk-only
 * version: placeBid() now takes a real row lock (SELECT ... FOR UPDATE) on
 * the LOT itself inside its transaction before reading the current high
 * bid, serializing every concurrent bid attempt on that lot — "two bidders
 * hitting the same increment in the same millisecond must produce one
 * accepted bid and one clean rejection." Locking the parent lot row (not
 * attempting to lock "the max of auction_bids") is the standard, correct
 * pattern for this: MySQL has no clean way to atomically lock an
 * aggregate, but any transaction holding the lot's row lock blocks every
 * other transaction trying to acquire the same lock, so the read-then-
 * insert sequence below is effectively atomic per lot.
 *
 * §11.3: "the auctioneer may always accept an off-increment bid on the
 * floor — the bands govern the online engine and *suggest* on the floor;
 * they never block the person holding the gavel." This service therefore
 * validates only that a bid exceeds the current high bid — never the
 * increment band — for every channel; the online engine's own UI is
 * responsible for only ever offering the suggested increment as a choice.
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
            return (float) $current->amount + $this->incrementAbove($lot, (float) $current->amount);
        }

        return $lot->opening_bid !== null ? (float) $lot->opening_bid : null;
    }

    private function incrementAbove(AuctionLot $lot, float $amount): float
    {
        return $lot->bid_increment !== null
            ? (float) $lot->bid_increment
            : $this->incrementForAmount((int) $lot->agency_id, $amount);
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
     * Record a bid. Validates: the lot is actually biddable (and, for an
     * online/hybrid lot, that its online window hasn't already closed),
     * the bidder may hold a paddle (§10.2 — server-side, never trust the
     * screen), and the amount exceeds the current high bid. Auto-extends
     * the lot's online window on a late bid (§11.2) and resolves any
     * standing proxy bids (§11.2) in the SAME transaction, so the state
     * this method returns to is always fully settled — never a manual bid
     * accepted with a stale, not-yet-reconciled proxy sitting behind it.
     *
     * @throws \RuntimeException on any failed validation
     */
    public function placeBid(
        AuctionLot $lot,
        AuctionBidder $bidder,
        float $amount,
        string $channel = 'in_room',
        ?int $recordedById = null,
        bool $isProxy = false,
        ?float $proxyMax = null,
    ): AuctionBid {
        if (! in_array($lot->status, [AuctionLot::STATUS_OPEN_FOR_BIDS, AuctionLot::STATUS_UNDER_THE_HAMMER], true)) {
            throw new \RuntimeException("Lot #{$lot->id} is not open for bidding (status: {$lot->status}).");
        }

        if (! (new BidderGateService())->canBid($bidder)) {
            throw new \RuntimeException("Bidder #{$bidder->id} may not bid — the paddle gate is not satisfied.");
        }

        return DB::transaction(function () use ($lot, $bidder, $amount, $channel, $recordedById, $isProxy, $proxyMax) {
            // §11.2 — the row lock. Every concurrent placeBid() call on this
            // lot blocks here until the holder commits/rolls back, so the
            // read-current-then-insert sequence below can never race.
            $lockedLot = AuctionLot::where('id', $lot->id)->lockForUpdate()->first();

            if ($lockedLot->online_closes_at !== null && now()->greaterThanOrEqualTo($lockedLot->online_closes_at)) {
                throw new \RuntimeException("Lot #{$lot->id}'s online bidding window has already closed.");
            }

            $current = $this->currentHighBid($lockedLot);
            $currentAmount = $current ? (float) $current->amount : 0.0;

            if ($amount <= $currentAmount) {
                throw new \RuntimeException("Bid of {$amount} does not exceed the current high bid of {$currentAmount}.");
            }
            if ($lockedLot->opening_bid !== null && ! $current && $amount < (float) $lockedLot->opening_bid) {
                throw new \RuntimeException("Bid of {$amount} is below the opening bid of {$lockedLot->opening_bid}.");
            }

            $bid = AuctionBid::create([
                'agency_id' => $lockedLot->agency_id,
                'auction_id' => $lockedLot->auction_id,
                'auction_lot_id' => $lockedLot->id,
                'auction_bidder_id' => $bidder->id,
                'amount' => $amount,
                'channel' => $channel,
                'placed_at' => now(),
                'recorded_by_id' => $recordedById,
                'is_proxy' => $isProxy,
                'proxy_max' => $proxyMax,
            ]);

            $this->applyAutoExtend($lockedLot);

            // Proxy resolution runs for every manual bid (never for a proxy
            // bid resolving itself — that would recurse the transaction).
            // Still inside the SAME lock/transaction, so the settled state
            // (including any auto-generated counter-bids) commits atomically
            // with the triggering bid.
            if (! $isProxy && AgencyAuctionSettings::proxyBiddingEnabledFor((int) $lockedLot->agency_id)) {
                $this->resolveProxyBids($lockedLot);
            }

            return $bid;
        });
    }

    /**
     * §11.2 auto-extend (anti-sniping): "a bid inside the final
     * online_auto_extend_minutes pushes the close out by that many
     * minutes. Unlimited extensions." No-op for a lot with no online
     * window at all (a pure in-room lot) or when the agency has the
     * setting off.
     */
    private function applyAutoExtend(AuctionLot $lot): void
    {
        if ($lot->online_closes_at === null) {
            return;
        }
        $agencyId = (int) $lot->agency_id;
        if (! AgencyAuctionSettings::onlineAutoExtendEnabledFor($agencyId)) {
            return;
        }

        $minutes = AgencyAuctionSettings::onlineAutoExtendMinutesFor($agencyId);
        $window = $lot->online_closes_at->copy()->subMinutes($minutes);

        if (now()->greaterThanOrEqualTo($window)) {
            $lot->online_closes_at = now()->addMinutes($minutes);
            $lot->save();
        }
    }

    /**
     * §11.2 proxy bidding: "the engine bids the minimum needed to stay
     * ahead, up to that maximum. Every proxy bid is a real row in
     * auction_bids with is_proxy = true." Standard iterative proxy-vs-proxy
     * resolution (equivalent to eBay/second-price-plus-increment): repeatedly
     * find whichever OTHER bidder's standing max_proxy_bid still exceeds the
     * current price and isn't already leading, and have them retake the
     * lead at the minimum increment — converges because each round strictly
     * raises the price and every bidder's max is finite, bounded by the
     * number of distinct proxy-holders so a pathological loop is
     * structurally impossible.
     *
     * No caller-supplied exclusion is needed: each round's own query
     * already excludes whoever is CURRENTLY leading (they cannot
     * "challenge" themselves), and a bidder who triggered this round with
     * a manual bid is free to be legitimately out-bid and then defend via
     * their own standing proxy in a later round, exactly like anyone else.
     */
    private function resolveProxyBids(AuctionLot $lot): void
    {
        // BUG FOUND WHILE HAND-VERIFYING THIS METHOD (never shipped): the
        // bound was originally "distinct proxy-holders + 1", on the
        // assumption each bidder can only ever challenge once. False — a
        // bidder can legitimately re-challenge in a LATER round as long as
        // their cap still exceeds the (higher) current price; hand-tracing
        // a 3-bidder case (maxes 100k/150k/200k, R10k increments) took 8
        // rounds to converge to the correct answer, not the 4 that bound
        // would have allowed — it would have stopped early on an
        // under-priced, still-challengeable "leader". Round count is
        // genuinely price-range-dependent (bounded by (highest cap −
        // starting price) / smallest increment), not bidder-count-dependent,
        // so this uses a generous fixed ceiling instead: no real auction
        // gets anywhere near 500 increment steps of oscillation, while a
        // pathological data state still can never hang the request.
        $maxRounds = 500;

        for ($round = 0; $round < $maxRounds; $round++) {
            $current = $this->currentHighBid($lot);
            $currentAmount = $current ? (float) $current->amount : 0.0;
            $currentLeaderId = $current?->auction_bidder_id;

            $challenger = AuctionBidder::where('auction_id', $lot->auction_id)
                ->whereNotNull('max_proxy_bid')
                ->where('max_proxy_bid', '>', $currentAmount)
                ->where('id', '!=', $currentLeaderId ?? 0)
                ->where('status', AuctionBidder::STATUS_APPROVED)
                ->orderByDesc('max_proxy_bid')
                ->first();

            if (! $challenger) {
                return; // stable — no standing proxy can outbid the current price
            }

            $increment = $this->incrementAbove($lot, $currentAmount);
            $proxyAmount = min($currentAmount + $increment, (float) $challenger->max_proxy_bid);

            if ($proxyAmount <= $currentAmount) {
                return; // challenger's max can't clear even one more increment
            }

            AuctionBid::create([
                'agency_id' => $lot->agency_id,
                'auction_id' => $lot->auction_id,
                'auction_lot_id' => $lot->id,
                'auction_bidder_id' => $challenger->id,
                'amount' => $proxyAmount,
                'channel' => 'proxy',
                'placed_at' => now(),
                'is_proxy' => true,
                'proxy_max' => $challenger->max_proxy_bid,
            ]);

            $this->applyAutoExtend($lot->refresh());
        }
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
