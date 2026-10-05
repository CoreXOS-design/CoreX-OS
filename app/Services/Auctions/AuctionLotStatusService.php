<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionCataloguePublished;
use App\Events\Auction\AuctionLotPassedIn;
use App\Events\Auction\AuctionLotSold;
use App\Events\Auction\AuctionLotSoldSubjectToConfirmation;
use App\Events\Auction\AuctionLotWithdrawn;
use App\Models\Auction;
use App\Models\AgencyAuctionSettings;
use App\Models\AuctionLot;
use App\Models\AuctionLotStatusHistory;
use App\Models\Property;
use Illuminate\Support\Facades\DB;

/**
 * AT-432 — .ai/specs/auctions.md §6.2. THE single owner of every
 * `auction_lots.status` transition. "One service owns every one of those
 * transitions, writes auction_lot_status_history, and emits the domain
 * events in §17. No controller sets a status directly." (§6.2)
 *
 * "The property's status follows the lot, not the other way round." (§6.2)
 */
class AuctionLotStatusService
{
    /** §6.2's state diagram as an adjacency list. */
    private const ALLOWED_TRANSITIONS = [
        AuctionLot::STATUS_DRAFT => [AuctionLot::STATUS_CATALOGUED, AuctionLot::STATUS_WITHDRAWN],
        AuctionLot::STATUS_CATALOGUED => [AuctionLot::STATUS_OPEN_FOR_BIDS, AuctionLot::STATUS_WITHDRAWN],
        AuctionLot::STATUS_OPEN_FOR_BIDS => [AuctionLot::STATUS_UNDER_THE_HAMMER, AuctionLot::STATUS_WITHDRAWN],
        AuctionLot::STATUS_UNDER_THE_HAMMER => [
            AuctionLot::STATUS_SOLD, AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION,
            AuctionLot::STATUS_PASSED_IN, AuctionLot::STATUS_WITHDRAWN,
        ],
        AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION => [AuctionLot::STATUS_SOLD, AuctionLot::STATUS_PASSED_IN],
        AuctionLot::STATUS_SOLD => [],
        AuctionLot::STATUS_PASSED_IN => [],
        AuctionLot::STATUS_WITHDRAWN => [],
    ];

    public function canTransition(AuctionLot $lot, string $toStatus): bool
    {
        return in_array($toStatus, self::ALLOWED_TRANSITIONS[$lot->status] ?? [], true);
    }

    /**
     * Publish an auction's catalogue: every `draft` lot moves to
     * `catalogued`, the property flips to "On Auction" with its prior
     * status snapshotted, and the auction itself becomes public. §9 step 5.
     *
     * @return AuctionLot[] the lots actually published
     */
    public function publishCatalogue(Auction $auction, ?int $actorId = null): array
    {
        return DB::transaction(function () use ($auction, $actorId) {
            // Defensive: a caller's in-memory $auction may still hold PHP's
            // pre-insert null for `status` (Eloquent does not re-fetch a
            // column's DB-level default after create()) rather than the
            // 'draft' the row actually has — refresh so the check below
            // reads the real persisted value, not a stale/absent one.
            $auction->refresh();
            $published = [];

            foreach ($auction->lots()->where('status', AuctionLot::STATUS_DRAFT)->get() as $lot) {
                $this->transition($lot, AuctionLot::STATUS_CATALOGUED, $actorId);
                $published[] = $lot;
            }

            if (in_array($auction->status, [Auction::STATUS_DRAFT, Auction::STATUS_SCHEDULED], true)) {
                $auction->status = Auction::STATUS_REGISTRATION_OPEN;
            }
            $auction->catalogue_published_at = $auction->catalogue_published_at ?? now();
            $auction->save();

            event(new AuctionCataloguePublished($auction, $actorId));

            return $published;
        });
    }

    public function openForBids(AuctionLot $lot, ?int $actorId = null): AuctionLot
    {
        return $this->transition($lot, AuctionLot::STATUS_OPEN_FOR_BIDS, $actorId);
    }

    public function startHammer(AuctionLot $lot, ?int $actorId = null): AuctionLot
    {
        return $this->transition($lot, AuctionLot::STATUS_UNDER_THE_HAMMER, $actorId);
    }

    /**
     * Record the fall of the hammer (§12.1). Phase 1 has no bidding engine,
     * so $winningBidId/$winningBidderId are null until Phase 2/3 land — the
     * columns are nullable for exactly this reason (see the auction_lots
     * migration's docblock).
     */
    public function recordHammer(
        AuctionLot $lot,
        float $hammerPrice,
        ?\DateTimeInterface $hammerAt = null,
        ?int $actorId = null,
        ?int $winningBidId = null,
        ?int $winningBidderId = null,
    ): AuctionLot {
        $reserveMet = $lot->reserve_price === null || $hammerPrice >= (float) $lot->reserve_price;
        $toStatus = $reserveMet ? AuctionLot::STATUS_SOLD : AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION;

        $extra = [
            'hammer_price' => $hammerPrice,
            'hammer_at' => $hammerAt ?? now(),
            'reserve_met' => $reserveMet,
            'winning_bid_id' => $winningBidId,
            'winning_bidder_id' => $winningBidderId,
        ];

        if (! $reserveMet) {
            $days = AgencyAuctionSettings::confirmationPeriodDaysFor((int) $lot->agency_id);
            $extra['confirmation_deadline'] = now()->addDays($days);
        }

        return $this->transition($lot, $toStatus, $actorId, $extra);
    }

    /**
     * AT-432 Phase 3 — the real Sale Room path: resolve the fall of the
     * hammer from the actual bid log (BidService) rather than a manually
     * entered price/bidder pair. Marks the winning AuctionBid `is_winning`
     * in the SAME transaction as the status transition, so a lot can never
     * end up `sold` with no bid marked winning or vice versa.
     *
     * @throws \RuntimeException when the lot has no active bid to knock down
     */
    public function recordHammerFromCurrentBid(AuctionLot $lot, ?\DateTimeInterface $hammerAt = null, ?int $actorId = null): AuctionLot
    {
        $bidService = new BidService();
        $winningBid = $bidService->currentHighBid($lot);

        if (! $winningBid) {
            throw new \RuntimeException("Lot #{$lot->id} has no active bid — nothing to knock down. Use markPassedIn() instead.");
        }

        return DB::transaction(function () use ($lot, $winningBid, $hammerAt, $actorId) {
            $winningBid->update(['is_winning' => true]);

            return $this->recordHammer(
                $lot,
                (float) $winningBid->amount,
                $hammerAt,
                $actorId,
                $winningBid->id,
                $winningBid->auction_bidder_id,
            );
        });
    }

    /**
     * AT-432 Phase 4 — .ai/specs/auctions.md §11.2: "the lot closes when
     * the window passes quietly." A pure online lot has no auctioneer to
     * click "knock down" — this is the automatic equivalent, run on a
     * schedule (see App\Console\Commands\Auctions\CloseExpiredAuctionLots)
     * against every lot whose online window has genuinely expired.
     *
     * Sold if there's a standing bid (via the normal fall-of-hammer path,
     * so the Deal-creation listener fires exactly as it would for a
     * hammer-fall click); passed in if there's none.
     *
     * §11.4 — a HYBRID lot is deliberately excluded: "closing is manual
     * (the hammer), not timed" there, since a human is running the floor.
     * Only a PURE online auction's lots close themselves.
     *
     * @return AuctionLot[] the lots actually closed
     */
    public function closeExpiredOnlineLots(): array
    {
        $closed = [];

        $expired = AuctionLot::whereIn('status', [AuctionLot::STATUS_OPEN_FOR_BIDS, AuctionLot::STATUS_UNDER_THE_HAMMER])
            ->whereNotNull('online_closes_at')
            ->where('online_closes_at', '<=', now())
            ->whereHas('auction', fn ($q) => $q->where('bidding_mode', 'online'))
            ->get();

        foreach ($expired as $lot) {
            try {
                if ($lot->status === AuctionLot::STATUS_OPEN_FOR_BIDS) {
                    $this->startHammer($lot);
                }

                if ((new BidService())->currentHighBid($lot)) {
                    $closed[] = $this->recordHammerFromCurrentBid($lot);
                } else {
                    $closed[] = $this->markPassedIn($lot, null, 'Online bidding window closed with no bids.');
                }
            } catch (\Throwable $e) {
                // Prevent-or-absorb — one bad lot must never block the rest
                // of the sweep. Logged, not silently swallowed.
                \Illuminate\Support\Facades\Log::error('closeExpiredOnlineLots failed for one lot', [
                    'auction_lot_id' => $lot->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $closed;
    }

    /** Seller confirms a below-reserve hammer (§6.2: sold_subject_to_confirmation → sold). */
    public function confirmSaleBelowReserve(AuctionLot $lot, ?int $actorId = null): AuctionLot
    {
        return $this->transition($lot, AuctionLot::STATUS_SOLD, $actorId, [
            'confirmed_at' => now(),
            'confirmed_by_id' => $actorId,
        ]);
    }

    /** Seller declines a below-reserve hammer (§6.2: sold_subject_to_confirmation → passed_in). */
    public function declineSaleBelowReserve(AuctionLot $lot, ?int $actorId = null, ?string $reason = null): AuctionLot
    {
        return $this->transition($lot, AuctionLot::STATUS_PASSED_IN, $actorId, [], $reason);
    }

    public function markPassedIn(AuctionLot $lot, ?int $actorId = null, ?string $reason = null): AuctionLot
    {
        return $this->transition($lot, AuctionLot::STATUS_PASSED_IN, $actorId, ['passed_in_at' => now()], $reason);
    }

    public function withdraw(AuctionLot $lot, ?int $actorId = null, ?string $reason = null): AuctionLot
    {
        return $this->transition($lot, AuctionLot::STATUS_WITHDRAWN, $actorId, [], $reason);
    }

    /**
     * The engine. Validates the edge, writes the lot + its extra columns,
     * updates the property's status where §6.2 says the lot's status
     * dictates it, writes auction_lot_status_history, and emits the domain
     * event for terminal/publish transitions. One transaction; no partial
     * completion (§12.1).
     */
    private function transition(AuctionLot $lot, string $toStatus, ?int $actorId, array $extra = [], ?string $reason = null): AuctionLot
    {
        // Same staleness guard as publishCatalogue() above: a caller's
        // in-memory $lot may still hold PHP's pre-insert null for `status`
        // rather than the persisted 'draft', which would make every
        // transition look invalid. Refresh before validating the edge.
        $lot->refresh();

        if (! $this->canTransition($lot, $toStatus)) {
            throw new \RuntimeException("Auction lot #{$lot->id}: cannot move from '{$lot->status}' to '{$toStatus}'.");
        }

        return DB::transaction(function () use ($lot, $toStatus, $actorId, $extra, $reason) {
            $fromStatus = $lot->status;

            $lot->fill($extra);
            $lot->status = $toStatus;
            $lot->save();

            AuctionLotStatusHistory::create([
                'agency_id' => $lot->agency_id,
                'auction_lot_id' => $lot->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'changed_by_id' => $actorId,
                'reason' => $reason,
            ]);

            $this->syncPropertyStatus($lot, $toStatus);

            match ($toStatus) {
                AuctionLot::STATUS_SOLD => event(new AuctionLotSold($lot, $actorId)),
                AuctionLot::STATUS_SOLD_SUBJECT_TO_CONFIRMATION => event(new AuctionLotSoldSubjectToConfirmation($lot, $actorId)),
                AuctionLot::STATUS_PASSED_IN => event(new AuctionLotPassedIn($lot, $actorId)),
                AuctionLot::STATUS_WITHDRAWN => event(new AuctionLotWithdrawn($lot, $reason, $actorId)),
                default => null,
            };

            return $lot;
        });
    }

    /**
     * §6.2: "The property's status follows the lot, not the other way
     * round." Catalogued → snapshot + "On Auction". Sold → "Sold". Passed
     * in / withdrawn (a genuine conclusion, not the interim
     * sold_subject_to_confirmation step) → restore `pre_auction_status`.
     * Every other transition leaves the property status untouched.
     */
    private function syncPropertyStatus(AuctionLot $lot, string $toStatus): void
    {
        $property = $lot->property;
        if (! $property) {
            return;
        }

        match ($toStatus) {
            AuctionLot::STATUS_CATALOGUED => (function () use ($property) {
                if (! $property->isAuction() || $property->pre_auction_status === null) {
                    $property->pre_auction_status = $property->status;
                }
                $property->status = Property::STATUS_ON_AUCTION;
                $property->save();
            })(),
            AuctionLot::STATUS_SOLD => (function () use ($property) {
                $property->status = 'sold';
                $property->pre_auction_status = null;
                $property->save();
            })(),
            AuctionLot::STATUS_PASSED_IN, AuctionLot::STATUS_WITHDRAWN => (function () use ($property) {
                if ($property->pre_auction_status !== null) {
                    $property->status = $property->pre_auction_status;
                    $property->pre_auction_status = null;
                    $property->save();
                }
            })(),
            default => null,
        };
    }
}
