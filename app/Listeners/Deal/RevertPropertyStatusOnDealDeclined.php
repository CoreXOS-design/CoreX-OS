<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealClosed;
use App\Models\AgencyDealSyncSettings;

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
            if ($agencyId <= 0 || empty($deal->property_id)) {
                return;
            }

            if (! AgencyDealSyncSettings::forAgency($agencyId)->revert_property_on_deal_declined) {
                return; // agency turned the companion OFF (default is ON).
            }

            $property = $deal->property;
            if (! $property) {
                return;
            }
            // Only revert a listing this feature flagged under-offer, and only when we
            // have the exact prior status to restore.
            if ((string) $property->status !== 'under_offer') {
                return;
            }
            $prior = $property->pre_deal_offer_status;
            if ($prior === null || $prior === '') {
                return;
            }

            $property->status = (string) $prior;
            $property->pre_deal_offer_status = null;
            $property->save(); // PropertyObserver: audit + re-syndication.
        } catch (\Throwable $e) {
            \Log::warning('Wave2 RevertPropertyStatusOnDealDeclined failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
