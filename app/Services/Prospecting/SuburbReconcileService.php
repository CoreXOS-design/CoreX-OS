<?php

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use App\Models\ProspectingSearch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
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
    /**
     * @return array{suburbs: array<int,string>, present: int, retired: int, retired_ids: array<int,int>, skipped_reason: ?string}
     */
    public function reconcile(int $agencyId, string $portalSource, ProspectingSearch $search, bool $dryRun = false): array
    {
        // The suburb(s) THIS capture actually covered — derived from the session's own
        // listings (last_search_id = this search), never from free text.
        $sessionSuburbs = ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal_source', $portalSource)
            ->where('last_search_id', $search->id)
            ->whereNotNull('suburb')
            ->where('suburb', '!=', '')
            ->distinct()
            ->pluck('suburb')
            ->all();

        $present = ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal_source', $portalSource)
            ->where('last_search_id', $search->id)
            ->count();

        // Safeguard: nothing recognisably captured for this search → do NOT reconcile.
        // (A zero/near-zero capture must never retire a suburb's whole stock.)
        if (empty($sessionSuburbs) || $present === 0) {
            return ['suburbs' => [], 'present' => $present, 'retired' => 0, 'retired_ids' => [], 'skipped_reason' => 'no session listings to reconcile against'];
        }

        // Gone = active listings in the SAME suburb(s), same portal, that this complete
        // capture did NOT include (last_search_id points at an older capture, or is null).
        $goneIds = ProspectingListing::withoutGlobalScopes()
            ->where('agency_id', $agencyId)
            ->where('portal_source', $portalSource)
            ->where('is_active', true)
            ->whereIn('suburb', $sessionSuburbs)
            ->where(function ($q) use ($search) {
                $q->whereNull('last_search_id')->orWhere('last_search_id', '!=', $search->id);
            })
            ->pluck('id')
            ->all();

        if ($dryRun) {
            return ['suburbs' => $sessionSuburbs, 'present' => $present, 'retired' => count($goneIds), 'retired_ids' => $goneIds, 'skipped_reason' => 'dry-run (no writes)'];
        }

        if (! empty($goneIds)) {
            DB::transaction(function () use ($goneIds) {
                $now = now();

                // SOFT retire — the row stays for lifecycle history; only its live flags change.
                ProspectingListing::withoutGlobalScopes()->whereIn('id', $goneIds)
                    ->update(['is_active' => false]);

                // Stamp the exit date once (days-on-market stays truthful on re-runs).
                ProspectingListing::withoutGlobalScopes()->whereIn('id', $goneIds)
                    ->whereNull('off_market_at')
                    ->update(['off_market_at' => $now]);

                // Gone from a for-sale suburb search = no longer available. Label 'withdrawn'
                // but NEVER overwrite an explicit sold/under_offer already recorded.
                ProspectingListing::withoutGlobalScopes()->whereIn('id', $goneIds)
                    ->where(function ($q) {
                        $q->whereNull('portal_status')->orWhere('portal_status', ProspectingListing::PORTAL_STATUS_ACTIVE);
                    })
                    ->update([
                        'portal_status'            => ProspectingListing::PORTAL_STATUS_WITHDRAWN,
                        'portal_status_changed_at' => $now,
                    ]);

                // Their cached buyer-match score is now for an off-market listing — purge it.
                DB::table('prospecting_buyer_matches')->whereIn('prospecting_listing_id', $goneIds)->delete();
            });

            Log::info('MIC suburb reconcile — retired listings gone from a complete capture', [
                'agency_id'     => $agencyId,
                'portal_source' => $portalSource,
                'search_id'     => $search->id,
                'suburbs'       => $sessionSuburbs,
                'present'       => $present,
                'retired'       => count($goneIds),
            ]);
        }

        return ['suburbs' => $sessionSuburbs, 'present' => $present, 'retired' => count($goneIds), 'retired_ids' => $goneIds, 'skipped_reason' => null];
    }
}
