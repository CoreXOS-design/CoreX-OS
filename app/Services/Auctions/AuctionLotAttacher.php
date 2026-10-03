<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * AT-432 — the ONE place a property becomes an auction lot (.ai/specs/auctions.md
 * §9 steps 1-2). Shared by the Auction catalogue builder (AuctionController), the
 * "Send to Auction" path, and the "On Auction" choice on the New Property forms
 * (classic + wizard), so the entry points can never drift apart on what "attach"
 * means. `sale_method` only ever flips to 'auction' together with a real lot
 * existing — never a property flagged on-auction with no lot behind it.
 */
class AuctionLotAttacher
{
    /** Listings that are concluded or off the books — never offered for, or accepted into, an auction. */
    public const INELIGIBLE_PROPERTY_STATUSES = [
        'sold', 'sold_by_3rd_party', 'transferred', 'withdrawn', 'expired', 'cancelled',
        'let_out', 'rented', 'archived', 'unavailable', 'not_selling',
    ];

    /** Auctions that are over: a lot left open in one of these no longer holds the property. */
    private const FINISHED_AUCTION_STATUSES = [Auction::STATUS_CLOSED, Auction::STATUS_SETTLED, Auction::STATUS_CANCELLED];

    /**
     * Why this property may not become a lot in this auction, in words an agent can act on — null when
     * it may. A property is sold once: it cannot sit open in two auctions at the same time, and a rental
     * or a concluded listing is not auction stock.
     */
    public static function blockReason(Auction $auction, Property $property): ?string
    {
        if ($property->isRental()) {
            return 'A rental listing cannot be put on auction.';
        }
        if (in_array($property->status, self::INELIGIBLE_PROPERTY_STATUSES, true)) {
            return 'This property is marked '.$property->statusBadge().' — it cannot be put on auction.';
        }

        $open = $property->auctionLots()
            ->where('auction_id', '!=', $auction->id)
            ->whereNotIn('status', AuctionLot::CONCLUDED_STATUSES)
            ->whereHas('auction', fn ($q) => $q->whereNotIn('status', self::FINISHED_AUCTION_STATUSES))
            ->with('auction:id,reference,title')
            ->first();
        if ($open) {
            return "This property is already Lot {$open->lot_number} in {$open->auction->reference} — {$open->auction->title}. Remove or withdraw it there first.";
        }

        return null;
    }

    /** The same rule as blockReason(), as a query constraint — what the Attach a Property search may offer. */
    public static function constrainToEligible($query, Auction $auction)
    {
        return $query
            ->whereNotIn('status', self::INELIGIBLE_PROPERTY_STATUSES)
            ->where(fn ($q) => $q->whereNull('listing_type')->orWhereNotIn('listing_type', ['rental', 'to_let', 'to-let', 'lease']))
            ->whereDoesntHave('auctionLots', fn ($q) => $q
                ->where('auction_id', '!=', $auction->id)
                ->whereNotIn('status', AuctionLot::CONCLUDED_STATUSES)
                ->whereHas('auction', fn ($a) => $a->whereNotIn('status', self::FINISHED_AUCTION_STATUSES)));
    }

    /** Auctions a new property can still be placed in: not yet over or cancelled. Agency-scoped by the model. */
    public static function openAuctions(): Collection
    {
        return Auction::query()
            ->whereNotIn('status', [Auction::STATUS_CLOSED, Auction::STATUS_SETTLED, Auction::STATUS_CANCELLED])
            ->orderBy('starts_at')
            ->get(['id', 'reference', 'title', 'starts_at']);
    }

    public function attach(Auction $auction, Property $property, array $priceFields = []): AuctionLot
    {
        // Idempotent: a property is one lot in a given auction. Every entry point (catalogue builder,
        // Send to Auction, the New Property forms) goes through here, so a repeat/double-submit can
        // never create a second lot for the same property.
        if ($existing = $auction->lots()->where('property_id', $property->id)->first()) {
            return $existing;
        }

        if ($reason = self::blockReason($auction, $property)) {
            throw ValidationException::withMessages(['property_id' => $reason]);
        }

        // Removed lots keep their number (the row is archived, and the auction+lot-number pair stays
        // unique across archived rows), so the next number counts them too.
        $nextLotNumber = (int) ($auction->lots()->withTrashed()->max('lot_number') ?? 0) + 1;

        $lot = AuctionLot::create([
            'agency_id' => $auction->agency_id,
            'auction_id' => $auction->id,
            'property_id' => $property->id,
            'lot_number' => $nextLotNumber,
            'reserve_price' => $priceFields['reserve_price'] ?? null,
            'guide_price_min' => $priceFields['guide_price_min'] ?? null,
            'guide_price_max' => $priceFields['guide_price_max'] ?? null,
            'opening_bid' => $priceFields['opening_bid'] ?? null,
        ]);

        $property->sale_method = 'auction';
        $property->save();

        return $lot;
    }
}
