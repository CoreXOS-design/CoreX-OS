<?php

namespace App\Services\Auctions;

use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;
use Illuminate\Support\Collection;

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

        $nextLotNumber = (int) ($auction->lots()->max('lot_number') ?? 0) + 1;

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
