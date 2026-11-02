<?php

namespace App\Services\Matching;

use App\Services\PropertyMatchScoringService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Is a buyer's MIC match cache (prospecting_buyer_matches) built under the CURRENT rules? (.ai/specs/mic-work-tab.md 13.15 addendum)
 *
 * Every row the writers produce carries `matched_features.rules = BuyerMatchRules::VERSION`. A row without it (or with an older one) was built before the
 * rules last changed, so it may show a property that is no longer a match. When a buyer is selected on MIC and any of his rows is stale, the page rebuilds
 * THAT buyer once (12-30 s) before it shows the list; the rebuild stamps every row it writes, so it happens once per rule change, whatever the change was.
 */
class BuyerMatchFreshness
{
    public function isStale(int $agencyId, int $contactId): bool
    {
        return DB::table('prospecting_buyer_matches')->where('agency_id', $agencyId)->where('contact_id', $contactId)
            ->where(fn ($q) => $q->whereNull('matched_features')->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(matched_features, '$.rules')) IS NULL")
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(matched_features, '$.rules')) <> ?", [BuyerMatchRules::VERSION]))
            ->exists();
    }

    /**
     * Rebuild one buyer now. One rebuild per buyer at a time: a second caller while it runs gets 'busy' and asks again.
     *
     * @return 'done'|'busy'
     */
    public function refresh(int $agencyId, int $contactId): string
    {
        $lock = Cache::lock('mic-buyer-refresh:' . $agencyId . ':' . $contactId, 240);
        if (! $lock->get()) {
            return 'busy';
        }
        try {
            if ($this->isStale($agencyId, $contactId)) { // someone else may have finished it while we waited for the lock
                app(PropertyMatchScoringService::class)->recomputeProspectingMatchesForBuyer($contactId);
            }
        } finally {
            $lock->release();
        }

        return 'done';
    }
}
