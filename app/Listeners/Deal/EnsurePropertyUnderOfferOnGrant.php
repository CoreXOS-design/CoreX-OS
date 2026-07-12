<?php

declare(strict_types=1);

namespace App\Listeners\Deal;

use App\Events\Deal\DealStageAdvanced;
use App\Models\AgencyDealSyncSettings;

/**
 * DR2 Wave 2 — keep property status honest across the RE-GRANT path.
 *
 * `under_offer` is normally set when a deal is CREATED. But a deal can also
 * become granted without a fresh creation: after the granted deal falls through
 * and the property reverts to on-market, an auto-declined sibling is RE-GRANTED
 * (Declined → Granted). At that moment the property is back on-market with no
 * listener to flag it. This listener closes that gap: on any deal reaching
 * Granted, if the property is still on-market (and not being sold by the
 * milestone listener), flag it under-offer and remember the prior status.
 *
 * Runs order-independently of MarkPropertySoldOnDealMilestone: it acts only on
 * an ON-MARKET property, so once the milestone listener has set 'sold'
 * (off-market) this is a no-op. Respects the same feature flag as the create-time
 * under-offer flagging.
 */
class EnsurePropertyUnderOfferOnGrant
{
    public function handle(DealStageAdvanced $event): void
    {
        try {
            if ($event->toStage !== 'G') {
                return;
            }

            $deal = $event->deal;
            $agencyId = (int) ($deal->agency_id ?? 0);
            if ($agencyId <= 0 || empty($deal->property_id)) {
                return;
            }

            if (! AgencyDealSyncSettings::forAgency($agencyId)->flag_property_under_offer_on_deal) {
                return; // feature OFF — same gate as the create-time flagging.
            }

            // Read the property FRESH — the deal's cached ->property relation can be
            // stale (loaded at deal-create time as 'under_offer', now reverted), which
            // would make the guard below wrongly skip the re-grant re-flag.
            $property = \App\Models\Property::withoutGlobalScopes()->find($deal->property_id);
            if (! $property) {
                return;
            }
            // Only act on a live listing. If it is already under-offer nothing to
            // do; if it is off-market (e.g. the milestone listener just sold it, or
            // a genuinely sold twin), never resurrect it.
            if (! $property->isOnMarket() || (string) $property->status === 'under_offer') {
                return;
            }

            $property->pre_deal_offer_status = (string) $property->status;
            $property->status = 'under_offer';
            $property->save(); // PropertyObserver: audit + re-syndication.
        } catch (\Throwable $e) {
            \Log::warning('Wave2 EnsurePropertyUnderOfferOnGrant failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
