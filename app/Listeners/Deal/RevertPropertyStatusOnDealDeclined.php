<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealClosed;
use App\Models\AgencyDealSyncSettings;
use App\Services\Deal\DealPropertyStatusService;

/**
 * DR2 Wave 2 (c) — the safety companion, ON by default. Deal declined / lapsed →
 * the property auto-reverts to the on-market status it held BEFORE the deal flagged
 * it under-offer (captured in properties.pre_deal_offer_status). Reverts a property
 * currently 'under_offer' OR 'sold' (2026-09-15 — a granted-then-sold deal can still
 * be declined afterward, e.g. a bond falling through; 'sold' is not a harder-than-
 * revertible state when THIS deal is what put it there), provided we have a prior
 * status to restore and no other active deal still holds the property. Never
 * clobbers a manually-changed listing, or a property genuinely sold/committed via a
 * DIFFERENT still-active deal (the otherActiveDealsExistForProperty check below).
 * PropertyObserver audits the revert + re-syndicates.
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
                    // Bug found live on QA1, 2026-09-15 (Johan, deal #183):
                    // walked pending -> under-offer (correct) -> granted ->
                    // sold (correct) -> declined -> BOTH properties stayed
                    // sold, neither reverted. This guard used to require
                    // 'under_offer' exactly, so a property MarkPropertySoldOnDealMilestone
                    // had already advanced to 'sold' was skipped before ever
                    // reaching the otherActiveDealsExistForProperty check
                    // below — proven on real property_audit_log timestamps:
                    // zero audit rows for either property at decline time.
                    // 'sold' is not a harder-than-revertible terminal state
                    // here — it's exactly the state a granted-then-declined
                    // deal leaves behind, and it must revert too, subject to
                    // the SAME aggregate check as under-offer (a genuinely
                    // sold-via-another-still-active-deal property is still
                    // protected by that check below, unchanged).
                    if (! in_array((string) $property->status, ['under_offer', 'sold'], true)) {
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
