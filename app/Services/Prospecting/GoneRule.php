<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\MicCrawl;
use App\Models\ProspectingListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The Gone rule (mic-work-tab.md 6.5 / 11.3.D). An advert that is on the market, or under offer, in a suburb a COMPLETE, UNFILTERED crawl covered
 * and that this crawl did not see (last_seen_at before the crawl began) is no longer on the portal:
 *   - last status Under offer  -> `probably_sold`
 *   - otherwise                -> `gone`
 * Soft only (is_active = 0, off_market_at stamped once); `sold` is never touched; a crawl that saw nothing, is partial or is filtered changes nothing.
 * A re-sighting clears it again (ProspectingListingLifecycleObserver). The sale / rent split follows the crawl's own search.
 */
final class GoneRule
{
    /**
     * @return array{gone:int, probably_sold:int, ids:list<int>, skipped_reason:?string}
     */
    public function apply(MicCrawl $crawl, bool $dryRun = false): array
    {
        $none = ['gone' => 0, 'probably_sold' => 0, 'ids' => []];
        if (! $crawl->is_complete) {
            return $none + ['skipped_reason' => 'the crawl is not complete'];
        }
        if ($crawl->is_filtered) {
            return $none + ['skipped_reason' => 'the search was filtered (price, beds, type or sold/under-offer excluded) - not a picture of the whole suburb'];
        }
        if ($crawl->adverts_seen <= 0 || $crawl->started_at === null) {
            return $none + ['skipped_reason' => 'the crawl saw no adverts'];
        }
        $subs = $crawl->relationLoaded('suburbs') ? $crawl->suburbs : $crawl->suburbs()->get();
        $ids = $subs->pluck('suburb_id')->filter()->map(fn ($i) => (int) $i)->unique()->values()->all();
        $names = $subs->pluck('suburb_name')->filter()->map(fn ($n) => mb_strtolower(trim((string) $n)))->unique()->values()->all();
        if (! $ids && ! $names) {
            return $none + ['skipped_reason' => 'no suburb recognised on the adverts seen'];
        }

        $q = ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $crawl->agency_id)->where('portal_source', $crawl->portal)->where('listing_type', $crawl->listing_type)
            ->where(fn ($w) => $w->where('is_active', true)->orWhere('portal_status', ProspectingListing::PORTAL_STATUS_UNDER_OFFER))
            ->where(fn ($w) => $w->whereNull('portal_status')->orWhere('portal_status', '!=', ProspectingListing::PORTAL_STATUS_SOLD))
            ->where('last_seen_at', '<', $crawl->started_at)
            ->where(function ($w) use ($ids, $names) {
                if ($ids) {
                    $w->whereIn('p24_suburb_id', $ids);
                }
                if ($names) {
                    // an advert whose suburb has no id yet is matched by its literal name
                    $w->orWhere(fn ($x) => $x->whereNull('p24_suburb_id')->whereIn(DB::raw('LOWER(TRIM(suburb))'), $names));
                }
            });

        $rows = $q->get(['id', 'portal_status']);
        $probablySold = $rows->where('portal_status', ProspectingListing::PORTAL_STATUS_UNDER_OFFER)->pluck('id')->all();
        $gone = $rows->pluck('id')->diff($probablySold)->values()->all();
        $out = ['gone' => count($gone), 'probably_sold' => count($probablySold), 'ids' => $rows->pluck('id')->map(fn ($i) => (int) $i)->all(), 'skipped_reason' => null];
        if ($dryRun || $rows->isEmpty()) {
            return $out;
        }

        DB::transaction(function () use ($gone, $probablySold, $crawl) {
            $now = now();
            foreach ([[$gone, ProspectingListing::PORTAL_STATUS_GONE], [$probablySold, ProspectingListing::PORTAL_STATUS_PROBABLY_SOLD]] as [$set, $status]) {
                if (! $set) {
                    continue;
                }
                ProspectingListing::withoutGlobalScopes()->whereIn('id', $set)->update(['is_active' => false, 'portal_status' => $status, 'portal_status_changed_at' => $now]);
                ProspectingListing::withoutGlobalScopes()->whereIn('id', $set)->whereNull('off_market_at')->update(['off_market_at' => $now]);
                // their cached buyer-match score is for an off-market advert now (cache rows, rebuilt on demand)
                DB::table('prospecting_buyer_matches')->whereIn('prospecting_listing_id', $set)->delete();
            }
            $crawl->forceFill(['gone_count' => count($gone), 'probably_sold_count' => count($probablySold), 'gone_applied_at' => $now])->save();
        });
        Log::info('MIC Gone rule applied', ['agency_id' => $crawl->agency_id, 'crawl_id' => $crawl->id, 'portal' => $crawl->portal, 'gone' => $out['gone'], 'probably_sold' => $out['probably_sold']]);

        return $out;
    }
}
