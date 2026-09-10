<?php

namespace App\Listeners\Deal;

use App\Events\Deal\DealStageAdvanced;
use App\Models\AgencyDealSyncSettings;

/**
 * DR2 Wave 2 (b) — the agency chooses which milestone marks the property SOLD on
 * portals: commission GRANTED (accepted_status 'G') vs REGISTERED ('R'). When the
 * deal reaches (or passes) that stage, flag the linked property 'sold'. OFF by
 * default (null milestone). PropertyObserver audits + syndicates the change.
 */
class MarkPropertySoldOnDealMilestone
{
    /** accepted_status forward-progression ranks (mirrors DealObserver). */
    private const RANK = ['G' => 2, 'R' => 3];

    public function handle(DealStageAdvanced $event): void
    {
        try {
            $deal = $event->deal;
            $agencyId = (int) ($deal->agency_id ?? 0);
            if ($agencyId <= 0) {
                return;
            }

            $milestoneStage = AgencyDealSyncSettings::forAgency($agencyId)->soldMilestoneStage(); // 'G'|'R'|null
            if ($milestoneStage === null) {
                return; // OFF.
            }

            // Sold when the deal reaches OR passes the configured milestone (a P→R jump
            // still marks sold when the milestone is 'granted').
            $toRank = self::RANK[(string) $event->toStage] ?? 0;
            $milestoneRank = self::RANK[$milestoneStage] ?? 99;
            if ($toRank < $milestoneRank) {
                return;
            }

            // AT-398 — every property linked to the deal (deal_properties),
            // not just the one primary.
            $properties = $deal->properties()->get();
            if ($properties->isEmpty()) {
                return;
            }

            foreach ($properties as $property) {
                try {
                    $current = (string) $property->status;
                    // Idempotent; never override a HARDER terminal state.
                    if (in_array($current, ['sold', 'transferred', 'withdrawn', 'archived'], true)) {
                        continue;
                    }

                    $property->status = 'sold';
                    $property->pre_deal_offer_status = null; // sold is terminal — no revert target.
                    $property->save();
                } catch (\Throwable $e) {
                    \Log::warning('Wave2 MarkPropertySoldOnDealMilestone failed for one property', [
                        'error' => $e->getMessage(), 'deal_id' => $deal->id, 'property_id' => $property->id,
                    ]);
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('Wave2 MarkPropertySoldOnDealMilestone failed', [
                'error' => $e->getMessage(), 'deal_id' => $event->deal->id ?? null,
            ]);
        }
    }
}
