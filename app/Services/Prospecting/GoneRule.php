<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\MicAgencySetting;
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
     * @return array{gone:int, probably_sold:int, waiting:int, ids:list<int>, skipped_reason:?string}
     */
    public function apply(MicCrawl $crawl, bool $dryRun = false): array
    {
        $none = ['gone' => 0, 'probably_sold' => 0, 'waiting' => 0, 'ids' => []];
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
        // a suburb is only COVERED when the search itself is that suburb (a Uvongo search that shows a few Margate adverts has not read Margate). A town- or region-level
        // search names no suburb: then every suburb it recorded counts, as before.
        $named = array_values(array_unique(array_map('intval', array_filter(array_map(
            fn ($p24) => DB::table('p24_suburbs')->where('p24_id', $p24)->value('id'),
            $crawl->portal === 'p24' ? \App\Services\Prospecting\MicWork\WorkingSuburbs::p24IdsNamedByUrl((string) $crawl->search_url) : []
        )))));
        $searchNamesSuburb = $named !== [];
        if ($searchNamesSuburb) {
            $ids = array_values(array_intersect($ids, $named));
        }
        $names = $searchNamesSuburb ? [] : $subs->pluck('suburb_name')->filter()->map(fn ($n) => mb_strtolower(trim((string) $n)))->unique()->values()->all();
        if (! $ids && ! $names) {
            return $none + ['skipped_reason' => $searchNamesSuburb ? 'the adverts seen are not in the suburb the search names' : 'no suburb recognised on the adverts seen'];
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

        // one complete crawl can miss a live advert (P24 re-orders pages while they are read): it is only set gone after the agency's number of consecutive complete
        // crawls of its own suburb all missed it (default 2); every sighting resets the count
        $confirm = MicAgencySetting::for((int) $crawl->agency_id)->goneConfirmCrawls();
        $missed = $q->get(['id', 'portal_status', 'missed_crawls', 'missed_crawl_id']);
        $rows = $missed->filter(fn ($r) => (int) $r->missed_crawl_id === (int) $crawl->id ? (int) $r->missed_crawls >= $confirm : (int) $r->missed_crawls + 1 >= $confirm)->values();
        $waiting = $missed->reject(fn ($r) => $rows->contains('id', $r->id))->pluck('id')->all();
        $probablySold = $rows->where('portal_status', ProspectingListing::PORTAL_STATUS_UNDER_OFFER)->pluck('id')->all();
        $gone = $rows->pluck('id')->diff($probablySold)->values()->all();
        $out = ['gone' => count($gone), 'probably_sold' => count($probablySold), 'waiting' => count($waiting), 'ids' => $rows->pluck('id')->map(fn ($i) => (int) $i)->all(), 'skipped_reason' => null];
        if ($dryRun || ($rows->isEmpty() && $waiting === [])) {
            return $out;
        }

        DB::transaction(function () use ($gone, $probablySold, $waiting, $crawl) {
            if ($waiting) {
                ProspectingListing::withoutGlobalScopes()->whereIn('id', $waiting)->where(fn ($w) => $w->whereNull('missed_crawl_id')->orWhere('missed_crawl_id', '<>', $crawl->id))
                    ->update(['missed_crawls' => DB::raw('missed_crawls + 1'), 'missed_crawl_id' => $crawl->id]);
            }
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
        Log::info('MIC Gone rule applied', ['agency_id' => $crawl->agency_id, 'crawl_id' => $crawl->id, 'portal' => $crawl->portal, 'gone' => $out['gone'], 'probably_sold' => $out['probably_sold'], 'waiting_for_a_second_crawl' => $out['waiting']]);

        return $out;
    }
}
