<?php

namespace App\Services\Auctions;

use App\Models\AgencyAuctionSettings;
use App\Models\AuctionLot;
use App\Models\PerformanceSetting;

/**
 * AT-432 Phase 3 — .ai/specs/auctions.md §12.2: "buyer's premium (hammer ×
 * premium % + VAT, floored at buyers_premium_minimum)". Per-lot override
 * (auction_lots.buyers_premium_percent) beats the agency default.
 */
class BuyersPremiumCalculator
{
    /** Buyer's premium, INCLUDING VAT (matching Deal::total_commission's own
     *  "captured INCL VAT, bank reality" convention — see App\Models\Deal). */
    public function calculate(AuctionLot $lot): float
    {
        $agencyId = (int) $lot->agency_id;
        $hammerPrice = (float) ($lot->hammer_price ?? 0);
        if ($hammerPrice <= 0) {
            return 0.0;
        }

        $percent = $lot->buyers_premium_percent !== null
            ? (float) $lot->buyers_premium_percent
            : AgencyAuctionSettings::buyersPremiumPercentFor($agencyId);

        $premiumExVat = $hammerPrice * ($percent / 100);
        $vatInclusive = AgencyAuctionSettings::buyersPremiumVatInclusiveFor($agencyId);

        $premiumIncVat = $vatInclusive
            ? $premiumExVat
            : $premiumExVat * (1 + $this->vatRate());

        $minimum = AgencyAuctionSettings::buyersPremiumMinimumFor($agencyId);

        return $minimum !== null ? max($premiumIncVat, $minimum) : $premiumIncVat;
    }

    private function vatRate(): float
    {
        return ((float) PerformanceSetting::get('vat_rate', 15)) / 100.0;
    }
}
