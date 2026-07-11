<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealCreated;
use App\Models\AgencyDealSyncSettings;
use App\Models\Property;

/**
 * DR2 Wave 2 (a) — deal created with a linked property → auto-flag the property
 * UNDER OFFER (existing settled status). Agency-configurable, OFF by default.
 * The status change flows to portals via the existing syndication + is audit-logged
 * by PropertyObserver — this listener only sets the status; it never invents one.
 * Prevent-or-absorb: never break the deal save on a sync failure.
 */
class FlagPropertyUnderOfferOnDealCreated
{
    public function handle(DealCreated $event): void
    {
        try {
            $deal = $event->deal;
            $agencyId = (int) ($deal->agency_id ?? 0);
            if ($agencyId <= 0 || empty($deal->property_id)) {
                return; // DR1 free-text-only deals (no property_id) are safely skipped.
            }

            if (! AgencyDealSyncSettings::forAgency($agencyId)->flag_property_under_offer_on_deal) {
                return; // OFF by default.
            }

            $property = $deal->property;
            if (! $property) {
                return;
            }

            $current = (string) ($property->status ?? '');
            // Only flag an ON-MARKET listing; never move an off-market (sold/withdrawn/…)
            // or already-under-offer property.
            if ($current === 'under_offer' || in_array($current, Property::OFF_MARKET_STATUSES, true)) {
                return;
            }

            // Remember the prior on-market status so the decline-revert companion (c)
            // can restore it exactly.
            $property->pre_deal_offer_status = $current !== '' ? $current : null;
            $property->status = 'under_offer';
            $property->save(); // PropertyObserver: audit + P24 syndication fire on the status change.
        } catch (\Throwable $e) {
            \Log::warning('Wave2 FlagPropertyUnderOfferOnDealCreated failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
