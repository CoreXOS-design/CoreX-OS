<?php

namespace App\Services\Auctions;

use App\Events\Auction\AuctionLotPassedIn;
use App\Events\Auction\AuctionLotSold;
use App\Events\Auction\AuctionLotSoldSubjectToConfirmation;
use App\Events\Auction\LotDeclinedBySeller;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * How a lot ends (.ai/specs/auctions.md §8.6). The same three outcomes whether the clock closes a timed lot or the
 * auctioneer's hammer falls on a live one:
 *
 *   highest valid bid at or above the reserve (or no reserve)  -> sold
 *   highest valid bid below the reserve                        -> sold subject to confirmation: the seller confirms
 *                                                                 (-> sold) or declines (-> passed in) before the deadline
 *   no valid bid, or the auctioneer passes the lot in          -> passed in (the property is released)
 *
 * Every transition is idempotent: it only acts on a lot in the right state, once. Events go out after the commit.
 */
class LotResultService
{
    public function __construct(private AuctionLotService $lots) {}

    // ── timed auctions: the clock ──────────────────────────────────────────

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
            $lot = $this->lock($lotId);
            if (! $lot || ! in_array($lot->status, [AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_OPEN], true) || $lot->auction->format !== 'timed') {
                return null;
            }
            $closes = $lot->windowClosesAt();
            if (! $closes || $closes->gt(now())) {
                return null;
            }

            $event = $this->applyResult($lot);
            $this->closeAuctionIfFinished($lot->auction);

            return $lot->status;
        });

        if ($event) {
            event($event);
        }

        return $status;
    }

    // ── live auctions: the room ────────────────────────────────────────────

    /** The auctioneer puts a lot on the block. One lot at a time. */
    public function openLot(AuctionLot $lot): AuctionLot
    {
        return DB::transaction(function () use ($lot) {
            $lot = $this->lock($lot->id) ?? $lot;
            $auction = $lot->auction;

            if ($auction->format !== 'live') {
                throw $this->refuse('Timed auctions open and close on their own clock.');
            }
            if (! in_array($auction->status, [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN], true)) {
                throw $this->refuse('This auction is not published.');
            }
            if ($lot->status === AuctionLot::STATUS_OPEN) {
                return $lot;
            }
            if ($lot->status !== AuctionLot::STATUS_PUBLISHED) {
                throw $this->refuse('Only a published lot can be opened for bids.');
            }
            $running = AuctionLot::withoutGlobalScopes()->where('auction_id', $auction->id)->where('status', AuctionLot::STATUS_OPEN)->whereNull('deleted_at')->first();
            if ($running) {
                throw $this->refuse("Lot {$running->lot_number} is still open. Knock it down or pass it in first.");
            }

            $lot->forceFill(['status' => AuctionLot::STATUS_OPEN])->save();
            if ($auction->status === Auction::STATUS_PUBLISHED) {
                $auction->forceFill(['status' => Auction::STATUS_OPEN])->save();
            }

            return $lot;
        });
    }

    /** The hammer falls on the highest bid: sold, or sold subject to confirmation if it is under the reserve. */
    public function hammer(AuctionLot $lot): string
    {
        $event = null;
        $status = DB::transaction(function () use ($lot, &$event) {
            $lot = $this->lock($lot->id);
            if (! $lot || $lot->auction->format !== 'live' || $lot->status !== AuctionLot::STATUS_OPEN) {
                throw $this->refuse('Only the lot that is open on the block can be knocked down.');
            }
            if (! $lot->highestBid()) {
                throw $this->refuse('There are no bids. Pass the lot in instead.');
            }

            $event = $this->applyResult($lot);
            $this->closeAuctionIfFinished($lot->auction);

            return $lot->status;
        });
        event($event);

        return $status;
    }

    /** The auctioneer passes the lot in, whatever the bids were. The property is released. */
    public function passIn(AuctionLot $lot, ?string $note = null): AuctionLot
    {
        $event = null;
        $lot = DB::transaction(function () use ($lot, $note, &$event) {
            $lot = $this->lock($lot->id);
            if (! $lot || $lot->auction->format !== 'live' || $lot->status !== AuctionLot::STATUS_OPEN) {
                throw $this->refuse('Only the lot that is open on the block can be passed in.');
            }
            $this->markPassedIn($lot, $note);
            $this->closeAuctionIfFinished($lot->auction);
            $event = new AuctionLotPassedIn($lot);

            return $lot;
        });
        event($event);

        return $lot;
    }

    // ── the seller's decision on a below-reserve sale ──────────────────────

    public function confirmSale(AuctionLot $lot): AuctionLot
    {
        $event = null;
        $lot = DB::transaction(function () use ($lot, &$event) {
            $lot = $this->lock($lot->id);
            if (! $lot || $lot->status !== AuctionLot::STATUS_SOLD_SUBJECT) {
                throw $this->refuse('This lot is not waiting for the seller\'s decision.');
            }
            $lot->forceFill(['status' => AuctionLot::STATUS_SOLD, 'confirmed_at' => now(), 'confirmation_deadline' => null])->save();
            $event = new AuctionLotSold($lot, true);

            return $lot;
        });
        event($event);

        return $lot;
    }

    public function declineSale(AuctionLot $lot, string $reason): AuctionLot
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'Note why the seller declined, for the record.']);
        }
        $event = null;
        $lot = DB::transaction(function () use ($lot, $reason, &$event) {
            $lot = $this->lock($lot->id);
            if (! $lot || $lot->status !== AuctionLot::STATUS_SOLD_SUBJECT) {
                throw $this->refuse('This lot is not waiting for the seller\'s decision.');
            }
            $this->markPassedIn($lot, 'Seller declined the highest bid: ' . trim($reason));
            $event = [new AuctionLotPassedIn($lot), new LotDeclinedBySeller($lot)];

            return $lot;
        });
        foreach ($event as $e) {
            event($e);
        }

        return $lot;
    }

    // ── internals ──────────────────────────────────────────────────────────

    private function lock(int $lotId): ?AuctionLot
    {
        $lot = AuctionLot::withoutGlobalScopes()->whereKey($lotId)->whereNull('deleted_at')->lockForUpdate()->first();
        $lot?->setRelation('auction', Auction::withTrashed()->withoutGlobalScopes()->find($lot->auction_id));

        return $lot;
    }

    /** Decide the outcome from the bids and the reserve, write it, and return the event to announce. */
    private function applyResult(AuctionLot $lot): object
    {
        $high = $lot->highestBid();
        if (! $high) {
            $this->markPassedIn($lot, null);

            return new AuctionLotPassedIn($lot);
        }

        $reserveMet = $lot->reserve_price === null || (float) $high->amount >= (float) $lot->reserve_price;
        $lot->forceFill([
            'status' => $reserveMet ? AuctionLot::STATUS_SOLD : AuctionLot::STATUS_SOLD_SUBJECT,
            'winning_bid_id' => $high->id,
            'hammer_price' => $high->amount,
            'result_at' => now(),
            'confirmation_deadline' => $reserveMet ? null : now()->addDays(AgencyAuctionSettings::confirmationDaysFor((int) $lot->agency_id)),
        ])->save();

        return $reserveMet ? new AuctionLotSold($lot) : new AuctionLotSoldSubjectToConfirmation($lot);
    }

    private function markPassedIn(AuctionLot $lot, ?string $note): void
    {
        $lot->forceFill([
            'status' => AuctionLot::STATUS_PASSED_IN, 'result_at' => $lot->result_at ?? now(),
            'confirmation_deadline' => null, 'result_note' => $note !== null ? mb_substr($note, 0, 300) : $lot->result_note,
        ])->save();
        $this->lots->releaseProperty($lot);
    }

    /** `closed` = the last running lot has ended (staff then complete the auction once every lot has a final result). */
    public function closeAuctionIfFinished(?Auction $auction): void
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

    private function refuse(string $message): ValidationException
    {
        return ValidationException::withMessages(['lot' => $message]);
    }
}
