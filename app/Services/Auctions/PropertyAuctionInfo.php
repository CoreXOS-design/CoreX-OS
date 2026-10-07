<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionLot;
use App\Models\Property;

/**
 * The auction a property is currently being sold through, shaped for display on
 * the property page (Auction tab) and the live preview. One place decides what
 * is shown and what is withheld (reserve amount follows the agency's reserve
 * visibility setting, exactly as on the public advert) so the two surfaces
 * can never disagree.
 */
class PropertyAuctionInfo
{
    /** @return array{lot: AuctionLot, auction: Auction, viewings: \Illuminate\Support\Collection, guideEnabled: bool, showReserve: bool, publicUrl: ?string, registerUrl: ?string}|null */
    public static function for(Property $property, bool $staff = false): ?array
    {
        if (! $property->isAuction()) {
            return null;
        }

        $lot = $property->currentAuctionLot();
        if (! $lot) {
            return null;
        }
        $lot->loadMissing(['auction.auctioneerUser', 'viewings.agent']);
        $auction = $lot->auction;
        if (! $auction) {
            return null;
        }

        $agencyId = (int) $auction->agency_id;
        $reserveVisible = AgencyAuctionSettings::reserveVisibilityFor($agencyId) === 'published';

        return [
            'lot' => $lot,
            'auction' => $auction,
            'viewings' => $lot->viewings->filter(fn ($v) => $v->ends_at === null || $v->ends_at->isFuture())->values(),
            'guideEnabled' => AgencyAuctionSettings::guidePriceEnabledFor($agencyId),
            // Staff may see the reserve with the permission; the preview mirrors the public advert.
            'showReserve' => $reserveVisible || ($staff && auth()->check() && auth()->user()->hasPermission('auctions.reserve.view')),
            'publicUrl' => $auction->isCataloguePublished() ? route('public.auctions.show', $auction->id) : null,
            'registerUrl' => $auction->external_registration_url ?: null,
        ];
    }
}
