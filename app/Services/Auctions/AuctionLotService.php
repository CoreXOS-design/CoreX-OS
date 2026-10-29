<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The one writer of auction lots (.ai/specs/auctions.md §6). The property's Auction tab and the Lots
 * screen both call it, so the two editors can never disagree: one property has at most one live lot.
 */
class AuctionLotService
{
    /**
     * Create or update a property's live lot.
     *
     * $data keys: auction_id (int|null — null with create_single_auction=true makes a one-property auction),
     * lot_number, title, reference, external_ref, description, opens_at, closes_at, opening_bid, reserve_price,
     * guide_price_min, guide_price_max.
     *
     * @throws ValidationException
     */
    public function saveForProperty(Property $property, array $data, User $user, ?AuctionLot $lot = null): AuctionLot
    {
        $lot ??= $property->currentAuctionLot();

        $opens = $this->date($data['opens_at'] ?? null);
        $closes = $this->date($data['closes_at'] ?? null);
        $opening = $this->money($data['opening_bid'] ?? null);
        $reserve = $this->money($data['reserve_price'] ?? null);
        $guideMin = $this->money($data['guide_price_min'] ?? null);
        $guideMax = $this->money($data['guide_price_max'] ?? null);

        $this->guardWindow($opens, $closes);
        if ($opening !== null && $reserve !== null && $reserve < $opening) {
            throw ValidationException::withMessages(['reserve_price' => 'The reserve cannot be lower than the opening bid.']);
        }
        if ($guideMin !== null && $guideMax !== null && $guideMax < $guideMin) {
            throw ValidationException::withMessages(['guide_price_max' => 'The top of the guide range cannot be lower than the bottom.']);
        }

        // Once there are bids the terms are fixed: changing the window, opening bid or reserve under a bidder's feet would
        // be unfair. Retract the bids (or withdraw the lot) first.
        if ($lot && $lot->hasBids()) {
            $same = fn ($a, $b) => ($a === null && $b === null) || ($a !== null && $b !== null && abs((float) $a - (float) $b) < 0.005);
            $sameTime = fn ($a, $b) => ($a === null && $b === null) || ($a !== null && $b !== null && $a->format('Y-m-d H:i') === $b->format('Y-m-d H:i'));
            if (! $sameTime($lot->windowOpensAt(), $opens) || ! $sameTime($lot->windowClosesAt(), $closes)
                || ! $same($lot->opening_bid, $opening) || ! $same($lot->reserve_price, $reserve)) {
                throw ValidationException::withMessages(['opening_bid' => 'This lot already has bids, so its bidding window, opening bid and reserve can no longer be changed. Retract the bids first, or withdraw the lot.']);
            }
        }

        return DB::transaction(function () use ($property, $data, $user, $lot, $opens, $closes, $opening, $reserve, $guideMin, $guideMax) {
            $auction = $this->resolveAuction($property, $data, $user, $lot, $opens, $closes);

            if ($auction->exists && ! $auction->is_single_property) {
                $this->guardInsideAuction($auction, $opens, $closes);
            }
            if (! $auction->isEditable()) {
                throw ValidationException::withMessages(['auction_id' => 'That auction is ' . strtolower($auction->statusLabel()) . ' and can no longer take changes.']);
            }

            $moving = $lot && (int) $lot->auction_id !== (int) $auction->id;
            if ($moving && $lot->status !== AuctionLot::STATUS_DRAFT) {
                throw ValidationException::withMessages(['auction_id' => 'This lot is already published. Withdraw it before moving the property to another auction.']);
            }

            $fields = [
                'title' => $this->str($data['title'] ?? null, 200),
                'reference' => $this->str($data['reference'] ?? null, 60),
                'external_ref' => $this->str($data['external_ref'] ?? null, 60),
                'description' => $this->str($data['description'] ?? null, 20000),
                'opens_at' => $opens,
                'closes_at' => $closes,
                'opening_bid' => $opening,
                'reserve_price' => $reserve,
                'guide_price_min' => $guideMin,
                'guide_price_max' => $guideMax,
            ];

            if ($lot && ! $moving) {
                $lot->fill($fields)->save();
            } else {
                $lot?->delete();     // archived, never hard-deleted
                $lot = AuctionLot::create($fields + [
                    'agency_id' => $property->agency_id,
                    'auction_id' => $auction->id,
                    'property_id' => $property->id,
                    'lot_number' => $this->lotNumber($auction, $data['lot_number'] ?? null),
                    'status' => $auction->isPublic() ? AuctionLot::STATUS_PUBLISHED : AuctionLot::STATUS_DRAFT,
                ]);
            }

            if (! $property->isAuction()) {
                $property->sale_method = 'auction';
                $property->save();
            }
            if ($lot->status === AuctionLot::STATUS_PUBLISHED && $property->status !== Property::STATUS_ON_AUCTION) {
                $property->pre_auction_status = $property->status;
                $property->status = Property::STATUS_ON_AUCTION;
                $property->save();
            }

            return $lot->setRelation('auction', $auction);
        });
    }

    /** Take a lot out of the auction. The property returns to its pre-auction status and may go into another auction. */
    public function withdraw(AuctionLot $lot, ?string $reason = null): AuctionLot
    {
        if ($lot->isConcluded()) {
            throw ValidationException::withMessages(['lot' => 'This lot has already finished.']);
        }

        $lot->forceFill(['status' => AuctionLot::STATUS_WITHDRAWN, 'result_at' => now(), 'result_note' => $this->str($reason, 300)])->save();
        $this->releaseProperty($lot);

        return $lot;
    }

    /** Put the property back the way it was before it went on auction (only if nothing else holds it). */
    public function releaseProperty(AuctionLot $lot): void
    {
        $property = $lot->property;
        if (! $property || $property->currentAuctionLot()) {
            return;
        }
        if ($property->status === Property::STATUS_ON_AUCTION) {
            $property->status = $property->pre_auction_status ?: 'active';
            $property->pre_auction_status = null;
        }
        $property->sale_method = 'private_treaty';
        $property->save();
    }

    private function resolveAuction(Property $property, array $data, User $user, ?AuctionLot $lot, ?Carbon $opens, ?Carbon $closes): Auction
    {
        $auctionId = $data['auction_id'] ?? null;

        if ($auctionId) {
            $auction = Auction::visibleTo($user)->find((int) $auctionId);
            if (! $auction) {
                throw ValidationException::withMessages(['auction_id' => 'Pick one of your upcoming auctions.']);
            }

            return $auction;
        }

        if ($lot && $lot->auction) {
            return $lot->auction;
        }

        // "Create a one-property auction" — needs a bidding window to build the auction from.
        if (! $opens || ! $closes) {
            throw ValidationException::withMessages(['opens_at' => 'Choose an auction, or enter when bidding opens and closes to create a one-property auction.']);
        }

        $agencyId = (int) $property->agency_id;
        $auction = Auction::create([
            'agency_id' => $agencyId,
            'branch_id' => $property->branch_id,
            'reference' => Auction::nextReference($agencyId),
            'title' => (string) ($property->title ?: 'Auction'),
            'format' => 'timed',
            'type' => 'real_estate',
            'currency' => 'ZAR',
            'status' => Auction::STATUS_DRAFT,
            'starts_at' => $opens,
            'ends_at' => $closes,
            'is_single_property' => true,
            'created_by' => $user->id,
        ]);
        $auction->syncIncrements(AgencyAuctionSettings::defaultIncrementsFor($agencyId));

        return $auction;
    }

    private function guardWindow(?Carbon $opens, ?Carbon $closes): void
    {
        if ($opens && $closes && $closes->lte($opens)) {
            throw ValidationException::withMessages(['closes_at' => 'Bidding must close after it opens.']);
        }
    }

    private function guardInsideAuction(Auction $auction, ?Carbon $opens, ?Carbon $closes): void
    {
        if ($opens && $auction->starts_at && $opens->lt($auction->starts_at)) {
            throw ValidationException::withMessages(['opens_at' => 'Bidding on a lot cannot open before the auction starts (' . $auction->starts_at->format('d M Y H:i') . ').']);
        }
        if ($closes && $auction->ends_at && $closes->gt($auction->ends_at)) {
            throw ValidationException::withMessages(['closes_at' => 'Bidding on a lot cannot close after the auction ends (' . $auction->ends_at->format('d M Y H:i') . ').']);
        }
    }

    private function lotNumber(Auction $auction, mixed $requested): int
    {
        $n = (int) $requested;
        if ($n < 1) {
            return AuctionLot::nextLotNumber($auction->id);
        }
        if (AuctionLot::withTrashed()->withoutGlobalScopes()->where('auction_id', $auction->id)->where('lot_number', $n)->exists()) {
            throw ValidationException::withMessages(['lot_number' => "Lot {$n} already exists in this auction."]);
        }

        return $n;
    }

    private function date(mixed $v): ?Carbon
    {
        return $v === null || $v === '' ? null : Carbon::parse((string) $v);
    }

    private function money(mixed $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }
        $clean = preg_replace('/[^\d.\-]/', '', str_replace(',', '.', (string) $v));

        return is_numeric($clean) ? round((float) $clean, 2) : null;
    }

    private function str(mixed $v, int $max): ?string
    {
        $s = trim((string) ($v ?? ''));

        return $s === '' ? null : mb_substr($s, 0, $max);
    }
}
