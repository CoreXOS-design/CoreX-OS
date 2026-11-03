<?php

namespace App\Services\Prospecting;

use App\Models\ProspectingSearch;

/**
 * AT-520 4.3: this service now delegates to the crawl record + Gone rule (mic-work-tab.md 11.3) - CrawlRecorder::finalise() marks the crawl complete
 * and records the suburbs it covered, GoneRule::apply() labels what it did not see `gone` / `probably_sold` (it used to say `withdrawn`, and never
 * looked at adverts under offer). A FILTERED search (price band, bedrooms, type) is not a complete picture of a suburb and retires nothing. The
 * return shape and the caller (import() and mic:reconcile-suburb) are unchanged. The text below is the original cc2 header.
 *
 * MIC SUBURB RECONCILE (cc2).
 *
 * When a COMPLETE suburb scrape lands, any currently-active listing in that suburb whose
 * portal_ref was NOT in the fresh capture is no longer on the portal — mark it off-market
 * (portal_status='withdrawn' + is_active=false), SOFT only (never a hard delete; the row
 * stays for lifecycle history). Present listings were already refreshed by the import.
 *
 * SAFEGUARD — completeness: this reconcile must ONLY ever run against a COMPLETE suburb
 * capture (all pages, no skipped pages). The scrape arrives in MANY partial batches (every
 * 100 listings), so a single import() POST is NEVER complete. The caller is responsible for
 * the completeness gate (the extension's `capture_complete` flag on its final batch, or a
 * human running the manual command). This service NEVER infers completeness itself — a
 * partial batch handed here would wrongly retire listings that are merely on a later page.
 *
 * Session identity: every batch of one capture upserts the SAME ProspectingSearch (per
 * search_url per day) and the import stamps `last_search_id = search->id` on every listing
 * it touches. So the full capture's listings — across all its batches — are exactly those
 * with `last_search_id = search->id`. "Gone" = active listings in the SAME suburb(s) whose
 * last_search_id is anything else (an older capture). Suburb-scoped so a Uvongo capture only
 * ever reconciles Uvongo stock.
 */
class SuburbReconcileService
{
    public function __construct(private readonly CrawlRecorder $recorder, private readonly GoneRule $rule)
    {
    }

    /**
     * @return array{suburbs: array<int,string>, present: int, retired: int, retired_ids: array<int,int>, skipped_reason: ?string}
     */
    public function reconcile(int $agencyId, string $portalSource, ProspectingSearch $search, bool $dryRun = false): array
    {
        // the caller guarantees completeness (the extension's final batch); the crawl records it and what it covered
        $crawl = $this->recorder->finalise($search, ! $dryRun);
        $suburbs = $crawl->suburbs->pluck('suburb_name')->filter()->values()->all();
        $r = $this->rule->apply($crawl, $dryRun);

        return [
            'suburbs'        => $suburbs,
            'present'        => (int) $crawl->adverts_seen,
            'retired'        => $r['gone'] + $r['probably_sold'],
            'retired_ids'    => $r['ids'],
            'skipped_reason' => $r['skipped_reason'] ?? ($dryRun ? 'dry-run (no writes)' : null),
        ];
    }
}
