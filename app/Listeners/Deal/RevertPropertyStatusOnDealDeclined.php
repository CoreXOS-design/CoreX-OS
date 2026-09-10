<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealClosed;
use App\Models\AgencyDealSyncSettings;
use App\Services\Deal\DealPropertyStatusService;

/**
 * DR2 Wave 2 (c) — the safety companion, ON by default. Deal declined / lapsed →
 * the property auto-reverts to the on-market status it held BEFORE the deal flagged
 * it under-offer (captured in properties.pre_deal_offer_status). Only reverts a
 * property that IS currently under-offer and that we have a prior status for — never
 * clobbers a manually-changed or already-sold listing. PropertyObserver audits the
 * revert + re-syndicates.
 */
class RevertPropertyStatusOnDealDeclined
{
    public function handle(DealClosed $event): void
    {
        try {
            // 'lost' = Declined; 'abandoned' = lapsed/fell-through. 'won' never reverts.
            if (! in_array($event->outcome, ['lost', 'abandoned'], true)) {
                return;
            }

            $deal = $event->deal;
            $agencyId = (int) ($deal->agency_id ?? 0);
            if ($agencyId <= 0) {
                return;
            }

            if (! AgencyDealSyncSettings::forAgency($agencyId)->revert_property_on_deal_declined) {
                return; // agency turned the companion OFF (default is ON).
            }

            // AT-398 — every property linked to the deal (deal_properties),
            // checked and reverted INDEPENDENTLY: on a multi-property deal,
            // property A can genuinely have no other active deal (reverts)
            // while property B still does (stays under-offer) — this is the
            // one listener where the mixed-status case is a real, expected
            // outcome, not an edge case to special-case away.
            $statusService = app(DealPropertyStatusService::class);
            $properties = $deal->properties()->get();
            if ($properties->isEmpty()) {
                return;
            }

            foreach ($properties as $property) {
                try {
                    // Only revert a listing this feature flagged under-offer, and only when we
                    // have the exact prior status to restore.
                    if ((string) $property->status !== 'under_offer') {
                        continue;
                    }
                    $prior = $property->pre_deal_offer_status;
                    if ($prior === null || $prior === '') {
                        continue;
                    }

                    // Wave 2 AGGREGATE rule — a property may carry multiple concurrent
                    // deals (two offers). Only revert to on-market when NO other active
                    // (pending/granted) deal remains on THIS property: deal 1 declined
                    // while deal 2 is still pending → this property STAYS under-offer.
                    if ($statusService->otherActiveDealsExistForProperty((int) $property->id, (int) $deal->id)) {
                        continue;
                    }

                    $property->status = (string) $prior;
                    $property->pre_deal_offer_status = null;
                    $property->save(); // PropertyObserver: audit + re-syndication.
                } catch (\Throwable $e) {
                    \Log::warning('Wave2 RevertPropertyStatusOnDealDeclined failed for one property', [
                        'error' => $e->getMessage(), 'deal_id' => $deal->id, 'property_id' => $property->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Wave2 RevertPropertyStatusOnDealDeclined failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
