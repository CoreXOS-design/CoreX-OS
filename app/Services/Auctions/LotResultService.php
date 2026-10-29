<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionLotPassedIn;
use App\Events\Auction\AuctionLotSold;
use App\Events\Auction\AuctionLotSoldSubjectToConfirmation;
use App\Models\Auction;
use App\Models\AuctionLot;
use Illuminate\Support\Facades\DB;

/**
 * Opens lots when their bidding window starts and closes them when it ends (.ai/specs/auctions.md §8.6, timed format).
 * Closing is idempotent: only a published/open lot whose closing time has passed is touched, once.
 *
 *   highest valid bid at or above the reserve (or no reserve)  -> sold
 *   highest valid bid below the reserve                        -> sold subject to confirmation (the seller decides, phase 4)
 *   no valid bid                                               -> passed in (the property is released)
 */
class LotResultService
{
    public function __construct(private AuctionLotService $lots) {}

    /** @return int how many lots were opened */
    public function openDueLots(): int
    {
        $n = 0;
        $due = AuctionLot::withoutGlobalScopes()->where('status', AuctionLot::STATUS_PUBLISHED)->whereNull('deleted_at')
            ->whereHas('auction', fn ($q) => $q->withoutGlobalScopes()->where('format', 'timed')->whereIn('status', [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN]))
            ->with(['auction' => fn ($q) => $q->withoutGlobalScopes()])->get();

        foreach ($due as $lot) {
            $opens = $lot->windowOpensAt();
            $closes = $lot->windowClosesAt();
            if ($opens && $opens->lte(now()) && (! $closes || $closes->gt(now()))) {
                $lot->forceFill(['status' => AuctionLot::STATUS_OPEN])->save();
                if ($lot->auction->status === Auction::STATUS_PUBLISHED) {
                    $lot->auction->forceFill(['status' => Auction::STATUS_OPEN])->save();
                }
                $n++;
            }
        }

        return $n;
    }

    /** @return int how many lots were concluded */
    public function closeDueLots(): int
    {
        $n = 0;
        $ids = AuctionLot::withoutGlobalScopes()->whereIn('status', [AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_OPEN])->whereNull('deleted_at')
            ->whereHas('auction', fn ($q) => $q->withoutGlobalScopes()->where('format', 'timed'))
            ->pluck('id');

        foreach ($ids as $id) {
            if ($this->conclude((int) $id) !== null) {
                $n++;
            }
        }

        return $n;
    }

    /** @return string|null the lot's new status, or null when nothing was due */
    public function conclude(int $lotId): ?string
    {
        $event = null;
        $status = DB::transaction(function () use ($lotId, &$event) {
            $lot = AuctionLot::withoutGlobalScopes()->whereKey($lotId)->lockForUpdate()->first();
            if (! $lot || ! in_array($lot->status, [AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_OPEN], true)) {
                return null;
            }
            $lot->setRelation('auction', Auction::withTrashed()->withoutGlobalScopes()->find($lot->auction_id));
            $closes = $lot->windowClosesAt();
            if (! $closes || $closes->gt(now())) {
                return null;
            }

            $high = $lot->highestBid();
            if (! $high) {
                $lot->forceFill(['status' => AuctionLot::STATUS_PASSED_IN, 'result_at' => now()])->save();
                $this->lots->releaseProperty($lot);
                $event = new AuctionLotPassedIn($lot);
            } else {
                $reserveMet = $lot->reserve_price === null || (float) $high->amount >= (float) $lot->reserve_price;
                $lot->forceFill([
                    'status' => $reserveMet ? AuctionLot::STATUS_SOLD : AuctionLot::STATUS_SOLD_SUBJECT,
                    'winning_bid_id' => $high->id,
                    'hammer_price' => $high->amount,
                    'result_at' => now(),
                ])->save();
                $event = $reserveMet ? new AuctionLotSold($lot) : new AuctionLotSoldSubjectToConfirmation($lot);
            }

            $this->closeAuctionIfFinished($lot->auction);

            return $lot->status;
        });

        if ($event) {
            event($event);
        }

        return $status;
    }

    /** `closed` = the last lot has ended (the auction is then completed by staff, once every lot has a final result). */
    private function closeAuctionIfFinished(?Auction $auction): void
    {
        if (! $auction || ! in_array($auction->status, [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN], true)) {
            return;
        }
        $stillRunning = AuctionLot::withoutGlobalScopes()->where('auction_id', $auction->id)->whereNull('deleted_at')
            ->whereIn('status', [AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_OPEN])->exists();
        if (! $stillRunning) {
            $auction->forceFill(['status' => Auction::STATUS_CLOSED])->save();
        }
    }
}
