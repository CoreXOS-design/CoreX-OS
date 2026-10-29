<?php

namespace App\Services\Auctions;

use App\Events\Auction\BidPlaced;
use App\Events\Auction\BidRetracted;
use App\Events\Auction\LotOutbid;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionAutoBid;
use App\Models\AuctionBid;
use App\Models\AuctionLot;
use App\Models\AuctionRegistration;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The bidding rules (.ai/specs/auctions.md §8.4). Every bid goes through here, inside one transaction that holds a row
 * lock on the lot, so two bidders can never both be accepted against the same price and a lower-than-high bid can never
 * land. Rules for a bid: the lot is open and inside its window; the bidder's registration for THIS auction is approved;
 * the amount is at least the current high bid plus the increment in force (or the opening bid for the first bid); and a
 * leading bidder cannot outbid themselves (they raise their auto-bid maximum instead).
 *
 * Auto-bids work like a proxy: the bidder states a maximum and the engine bids for them, one increment above the
 * competition, up to it. A late bid extends the closing time (soft close). Retractions are statuses, never deletes.
 */
class BidEngine
{
    /** Smallest amount the next bid must be. */
    public function minimumNext(AuctionLot $lot): float
    {
        $auction = $lot->auction ?? Auction::withTrashed()->findOrFail($lot->auction_id);
        $auction->loadMissing('increments');
        $high = $this->highestAmount($lot);

        if ($high === null) {
            return (float) ($lot->opening_bid ?? max($auction->incrementFor(0), 1));
        }

        return $high + max($auction->incrementFor($high), 1);
    }

    public function highestAmount(AuctionLot $lot): ?float
    {
        $bid = $lot->highestBid();

        return $bid ? (float) $bid->amount : null;
    }

    /**
     * Place a bid by hand. Returns the bidder's own bid; an auto-bid that counters it is stored as a further bid.
     *
     * @throws ValidationException with the message to show the bidder
     */
    public function place(AuctionLot $lot, AuctionRegistration $reg, float $amount, string $source = 'online', ?string $ip = null): AuctionBid
    {
        $outcome = DB::transaction(function () use ($lot, $reg, $amount, $source, $ip) {
            $lot = $this->lockLot($lot);
            $this->guardCanBid($lot, $reg, $source);

            $before = $lot->highestBid();
            if ($before && (int) $before->registration_id === (int) $reg->id) {
                throw $this->reject('You are already the highest bidder. To bid more, set a higher auto-bid maximum.');
            }
            $min = $this->minimumNext($lot);
            if ($amount + 0.00001 < $min) {
                throw $this->reject('Your bid must be at least ' . $this->fmt($min) . '.');
            }

            $bid = $this->insert($lot, $reg, $amount, $source, $ip);
            if ($source !== 'floor') {
                $this->extendIfLate($lot);   // the auctioneer closes a live lot with the hammer, not a clock
            }
            $this->settle($lot, $ip);
            $after = $lot->highestBid();

            return compact('bid', 'before', 'after', 'lot');
        });

        $this->announce($outcome);

        return $outcome['bid'];
    }

    /**
     * State a maximum. If the bidder is not leading, the engine immediately bids the minimum needed for them and then
     * defends the lead up to the maximum.
     *
     * @throws ValidationException
     */
    public function setAutoBid(AuctionLot $lot, AuctionRegistration $reg, float $max, ?string $ip = null): AuctionAutoBid
    {
        $outcome = DB::transaction(function () use ($lot, $reg, $max, $ip) {
            $lot = $this->lockLot($lot);
            $this->guardCanBid($lot, $reg, 'online');

            $before = $lot->highestBid();
            $leading = $before && (int) $before->registration_id === (int) $reg->id;
            $min = $this->minimumNext($lot);
            $floor = $leading ? (float) $before->amount + 0.01 : $min;
            if ($max + 0.00001 < $floor) {
                throw $this->reject($leading
                    ? 'Your maximum must be higher than your current bid of ' . $this->fmt((float) $before->amount) . '.'
                    : 'Your maximum must be at least ' . $this->fmt($min) . '.');
            }

            $this->autos($lot)->where('registration_id', $reg->id)->get()->each->delete();
            $auto = AuctionAutoBid::withoutAgencyStamping(fn () => AuctionAutoBid::create([
                'agency_id' => $lot->agency_id, 'lot_id' => $lot->id, 'registration_id' => $reg->id, 'max_amount' => $max, 'active' => true,
            ]));

            $bid = null;
            if (! $leading) {
                $bid = $this->insert($lot, $reg, $min, 'auto_bid', $ip);
                $this->extendIfLate($lot);
            }
            $this->settle($lot, $ip);
            $after = $lot->highestBid();

            return ['bid' => $bid, 'before' => $before, 'after' => $after, 'lot' => $lot, 'auto' => $auto];
        });

        if ($outcome['bid']) {
            $this->announce($outcome);
        }

        return $outcome['auto'];
    }

    public function cancelAutoBid(AuctionLot $lot, AuctionRegistration $reg): void
    {
        $this->autos($lot)->where('registration_id', $reg->id)->get()->each->delete();
    }

    /**
     * Staff retract a bid. The bidder's auto-bid on the lot is switched off (otherwise the engine would simply bid again),
     * and the lead falls back to the next-highest valid bid.
     */
    public function retract(AuctionBid $bid, User $by, string $reason): AuctionBid
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw $this->reject('Give a reason for retracting the bid.', 'reason');
        }

        DB::transaction(function () use ($bid, $by, $reason) {
            $lot = $this->lockLot(AuctionLot::withTrashed()->withoutGlobalScopes()->findOrFail($bid->lot_id));
            if ($lot->isConcluded()) {
                throw $this->reject('This lot has finished, so its bids can no longer be retracted.', 'bid');
            }
            $bid = AuctionBid::withoutGlobalScopes()->lockForUpdate()->findOrFail($bid->id);
            if (! $bid->isValid()) {
                return;
            }
            $bid->forceFill([
                'status' => AuctionBid::STATUS_RETRACTED, 'retracted_by' => $by->id, 'retracted_at' => now(), 'retract_reason' => mb_substr($reason, 0, 300),
            ])->save();

            $this->autos($lot)->where('registration_id', $bid->registration_id)->get()->each->delete();
            $this->settle($lot, null);
        });

        BidRetracted::dispatch($bid->refresh(), $by->id);

        return $bid;
    }

    /**
     * What the lot page / live feed shows. Never contains who the bidders are (unless the agency chose to show bidder
     * numbers) and never the reserve amount — only "met / not met" when the agency allows it.
     */
    public function state(AuctionLot $lot, ?AuctionRegistration $reg = null): array
    {
        $auction = $lot->auction ?? Auction::withTrashed()->findOrFail($lot->auction_id);
        $agencyId = (int) $lot->agency_id;
        $now = now();
        $opens = $lot->windowOpensAt();
        $closes = $lot->windowClosesAt();
        $high = $lot->highestBid();
        $concluded = $lot->isConcluded() || $lot->status === AuctionLot::STATUS_SOLD_SUBJECT;

        if ($auction->format === 'live') {
            // a live lot is open exactly while the auctioneer has it open; there is no clock
            $phase = $concluded ? 'ended' : ($lot->status === AuctionLot::STATUS_OPEN ? 'open' : 'upcoming');
            $closes = null;
        } else {
            $phase = $concluded || ($closes && $now->gte($closes)) ? 'ended' : (($opens && $now->lt($opens)) ? 'upcoming' : 'open');
        }

        $you = null;
        if ($reg) {
            $mine = $lot->bids()->valid()->where('registration_id', $reg->id)->exists();
            $you = $high && (int) $high->registration_id === (int) $reg->id ? 'winning' : ($mine ? 'outbid' : 'none');
        }

        $reserve = null;
        if ($lot->reserve_price !== null && AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'met_not_met' && $high) {
            $reserve = (float) $high->amount >= (float) $lot->reserve_price ? 'met' : 'not_met';
        }

        $out = [
            'lot_status' => $lot->status,
            'phase' => $phase,
            'server_now' => $now->toIso8601String(),
            'opens_at' => $opens?->toIso8601String(),
            'closes_at' => $closes?->toIso8601String(),
            'seconds_left' => $closes && $phase === 'open' ? max(0, $now->diffInSeconds($closes, false)) : 0,
            'current_bid' => $high ? (float) $high->amount : null,
            'opening_bid' => $lot->opening_bid !== null ? (float) $lot->opening_bid : null,
            'min_next' => $phase === 'ended' ? null : $this->minimumNext($lot),
            'reserve' => $reserve,
            'you' => $you,
            'currency' => $auction->currency,
        ];
        if (AgencyAuctionSettings::showBidCountFor($agencyId)) {
            $out['bid_count'] = $lot->bids()->valid()->count();
        }
        if ($high && AgencyAuctionSettings::showBidderNumbersFor($agencyId)) {
            $out['leader_number'] = AuctionRegistration::withoutGlobalScopes()->whereKey($high->registration_id)->value('bidder_number');
        }
        if ($reg) {
            $auto = $this->autos($lot)->where('registration_id', $reg->id)->where('active', true)->first();
            $out['your_auto_bid'] = $auto ? (float) $auto->max_amount : null;
        }

        return $out;
    }

    // ── internals ──────────────────────────────────────────────────────────

    /** A lot's live auto-bids, whoever is browsing (the lot's own agency is the only possible owner). */
    private function autos(AuctionLot $lot)
    {
        return AuctionAutoBid::withoutGlobalScopes()->whereNull('deleted_at')->where('lot_id', $lot->id);
    }

    /** The single serialisation point: every bid, auto-bid and retraction on a lot queues behind this row lock. */
    private function lockLot(AuctionLot $lot): AuctionLot
    {
        $locked = AuctionLot::withTrashed()->withoutGlobalScopes()->whereKey($lot->id)->lockForUpdate()->firstOrFail();
        $locked->setRelation('auction', Auction::withTrashed()->withoutGlobalScopes()->with('increments')->findOrFail($locked->auction_id));

        return $locked;
    }

    private function guardCanBid(AuctionLot $lot, AuctionRegistration $reg, string $source): void
    {
        $auction = $lot->auction;
        $reg = AuctionRegistration::withoutGlobalScopes()->find($reg->id);

        if (! $reg || ! $reg->isApproved() || (int) $reg->auction_id !== (int) $lot->auction_id) {
            throw $this->reject('You need an approved registration for this auction before you can bid.');
        }
        if (! in_array($auction->status, [Auction::STATUS_PUBLISHED, Auction::STATUS_OPEN], true) || $lot->trashed()) {
            throw $this->reject('This auction is not open for bidding.');
        }
        if ($source === 'floor') {
            // A bid called from the floor of a LIVE auction: valid only while the auctioneer has the lot open. No clock applies.
            if ($auction->format !== 'live') {
                throw $this->reject('Floor bids are only taken at a live auction.');
            }
            if ($lot->status !== AuctionLot::STATUS_OPEN) {
                throw $this->reject('Open the lot before taking bids on it.');
            }

            return;
        }
        if ($auction->format !== 'timed') {
            throw $this->reject('This is a live auction. Bids are taken in the room.');
        }
        if (! in_array($lot->status, [AuctionLot::STATUS_PUBLISHED, AuctionLot::STATUS_OPEN], true)) {
            throw $this->reject('Bidding on this lot has ended.');
        }
        $now = now();
        $opens = $lot->windowOpensAt();
        $closes = $lot->windowClosesAt();
        if ($opens && $now->lt($opens)) {
            throw $this->reject('Bidding on this lot opens ' . $opens->format('j M Y \a\t H:i') . '.');
        }
        if ($closes && $now->gte($closes)) {
            throw $this->reject('Bidding on this lot has closed.');
        }
    }

    private function insert(AuctionLot $lot, AuctionRegistration $reg, float $amount, string $source, ?string $ip): AuctionBid
    {
        $bid = AuctionBid::withoutAgencyStamping(fn () => AuctionBid::create([
            'agency_id' => $lot->agency_id, 'auction_id' => $lot->auction_id, 'lot_id' => $lot->id, 'registration_id' => $reg->id,
            'amount' => round($amount, 2), 'placed_at' => now(), 'source' => $source, 'status' => AuctionBid::STATUS_VALID, 'ip' => $ip,
        ]));

        if ($lot->status === AuctionLot::STATUS_PUBLISHED) {
            $lot->forceFill(['status' => AuctionLot::STATUS_OPEN])->save();
        }
        if ($lot->auction->status === Auction::STATUS_PUBLISHED) {
            $lot->auction->forceFill(['status' => Auction::STATUS_OPEN])->save();
        }

        return $bid;
    }

    /** A bid in the last N minutes pushes the closing time out by N (N = auction override, else the agency setting). */
    private function extendIfLate(AuctionLot $lot): void
    {
        $minutes = $lot->auction->soft_close_minutes ?? AgencyAuctionSettings::softCloseMinutesFor((int) $lot->agency_id);
        $closes = $lot->windowClosesAt();
        if ($minutes <= 0 || ! $closes || now()->lt($closes->copy()->subMinutes($minutes))) {
            return;
        }

        $lot->closes_at = $closes->copy()->addMinutes($minutes);
        $lot->save();
        if ($lot->auction->ends_at && $lot->closes_at->gt($lot->auction->ends_at)) {
            $lot->auction->forceFill(['ends_at' => $lot->closes_at])->save();
        }
    }

    /**
     * Auto-bid resolution, done directly rather than by looping bid-for-bid. Every registration has a "cap": its highest
     * bid or its auto-bid maximum, whichever is greater. The highest cap leads; it pays one increment above the second
     * highest cap, but never more than its own cap. Placed as at most ONE extra bid.
     */
    private function settle(AuctionLot $lot, ?string $ip): void
    {
        $autos = $this->autos($lot)->where('active', true)->orderBy('id')->get();
        $high = $lot->highestBid();
        if ($autos->isEmpty() || ! $high) {
            return;
        }

        $caps = [];   // registration id => ['cap' => float, 'order' => int]
        $lot->bids()->valid()->orderBy('id')->get()->each(function (AuctionBid $b) use (&$caps) {
            $r = (int) $b->registration_id;
            $caps[$r] = ['cap' => max($caps[$r]['cap'] ?? 0, (float) $b->amount), 'order' => $caps[$r]['order'] ?? $b->id];
        });
        foreach ($autos as $a) {
            $r = (int) $a->registration_id;
            $caps[$r] = ['cap' => max($caps[$r]['cap'] ?? 0, (float) $a->max_amount), 'order' => $caps[$r]['order'] ?? (PHP_INT_MAX - 1 - $a->id)];
        }

        $holder = (int) $high->registration_id;
        $leader = null;
        foreach ($caps as $r => $c) {
            if ($leader === null || $c['cap'] > $caps[$leader]['cap'] + 0.00001
                || (abs($c['cap'] - $caps[$leader]['cap']) < 0.00001 && ($r === $holder || ($leader !== $holder && $c['order'] < $caps[$leader]['order'])))) {
                $leader = $r;
            }
        }
        $second = 0.0;
        foreach ($caps as $r => $c) {
            if ($r !== $leader) {
                $second = max($second, $c['cap']);
            }
        }

        $increment = fn (float $at) => max($lot->auction->incrementFor($at), 1);
        $target = $second > 0 ? min($caps[$leader]['cap'], $second + $increment($second)) : (float) $high->amount;

        $needed = $leader === $holder ? (float) $high->amount + 0.00001 : (float) $high->amount + $increment((float) $high->amount) - 0.00001;
        if ($target < $needed) {
            return;
        }

        $reg = AuctionRegistration::withoutGlobalScopes()->find($leader);
        if ($reg && $reg->isApproved()) {
            $this->insert($lot, $reg, $target, 'auto_bid', $ip);
        }
    }

    /** Events go out after the transaction commits. */
    private function announce(array $o): void
    {
        /** @var AuctionBid|null $bid */
        $bid = $o['bid'];
        if ($bid) {
            BidPlaced::dispatch($bid);
        }
        $before = $o['before'];
        $after = $o['after'];
        if ($before && $after && $bid?->source !== 'floor' && (int) $before->registration_id !== (int) $after->registration_id) {
            $outbid = AuctionRegistration::withoutGlobalScopes()->find($before->registration_id);
            if ($outbid) {
                LotOutbid::dispatch($o['lot'], $outbid, (float) $after->amount);
            }
        }
    }

    private function reject(string $message, string $field = 'amount'): ValidationException
    {
        return ValidationException::withMessages([$field => $message]);
    }

    private function fmt(float $v): string
    {
        return 'R ' . number_format($v, 0, '.', ' ');
    }
}
