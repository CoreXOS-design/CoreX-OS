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
 *
 * AT-398 — loops over EVERY property linked to the deal (deal_properties,
 * which includes a backfilled row for old single-property deals too — see
 * DealPropertyOwnerGate/the deal_properties migration), not just the one
 * primary property. The per-property logic below is UNCHANGED — a property
 * already off-market is still left alone, exactly as before; only "how many
 * properties do we do this to" changed, from one to every linked property.
 * One property failing must never stop the others (§6 fix-the-class /
 * prevent-or-absorb) — each property is flagged independently inside its own
 * try/catch, all sharing the outer safety net for anything unexpected.
 */
class FlagPropertyUnderOfferOnDealCreated
{
    public function handle(DealCreated $event): void
    {
        try {
            $deal = $event->deal;
            $agencyId = (int) ($deal->agency_id ?? 0);
            if ($agencyId <= 0) {
                return;
            }

            if (! AgencyDealSyncSettings::forAgency($agencyId)->flag_property_under_offer_on_deal) {
                return; // OFF by default.
            }

            $properties = $deal->properties()->get();
            if ($properties->isEmpty()) {
                return; // DR1 free-text-only deals (no property linked) are safely skipped.
            }

            foreach ($properties as $property) {
                try {
                    $current = (string) ($property->status ?? '');
                    // Only flag an ON-MARKET listing; never move an off-market (sold/withdrawn/…)
                    // or already-under-offer property.
                    if ($current === 'under_offer' || in_array($current, Property::OFF_MARKET_STATUSES, true)) {
                        continue;
                    }

                    // Remember the prior on-market status so the decline-revert companion (c)
                    // can restore it exactly.
                    $property->pre_deal_offer_status = $current !== '' ? $current : null;
                    $property->status = 'under_offer';
                    $property->save(); // PropertyObserver: audit + P24 syndication fire on the status change.
                } catch (\Throwable $e) {
                    \Log::warning('Wave2 FlagPropertyUnderOfferOnDealCreated failed for one property', [
                        'error' => $e->getMessage(), 'deal_id' => $deal->id, 'property_id' => $property->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Wave2 FlagPropertyUnderOfferOnDealCreated failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
