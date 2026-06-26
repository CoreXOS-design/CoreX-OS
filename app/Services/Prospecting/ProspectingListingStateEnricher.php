<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Enriches a collection of prospecting_listings with cross-module state signals:
 *   - Pitch history    (seller_outreach_sends, most recent per matched property)
 *   - Claim status     (prospecting_claims, active only)
 *   - Presentation     (presentations linked via listing_id = matched_property_id)
 *   - Linked contacts  (contact_property count per matched property)
 *   - Promotion        (matched_property_id resolves to a live properties row)
 *
 * Bulk-first: 5 queries total regardless of input size. Designed to be called once
 * per request from ProspectingController::index, immediately before view render.
 *
 * Tenant safety: every join filters by agency_id. The caller MUST pass the viewer's
 * effective agency. Listings are already agency-scoped by the controller query.
 *
 * Returns:
 *   [
 *     'pitches'        => [listing_id => ['send_id','contact_id','sent_at','channel','outcome','agent_name','template_name','is_recent']],
 *     'claims'         => [listing_id => ['claim_id','user_id','claimer_name','status','claimed_at','hours_left','is_expiring']],
 *     'presentations'  => [listing_id => ['presentation_id','title','created_at','creator_name']],
 *     'contact_counts' => [listing_id => int],
 *     'promotions'     => [matched_property_id => true]  // keyed by property_id, NOT listing_id
 *   ]
 */
final class ProspectingListingStateEnricher
{
    public function enrich(iterable $listings, int $agencyId): array
    {
        $listingIds = [];
        $listingByPropertyId = [];
        foreach ($listings as $l) {
            $listingIds[] = (int) $l->id;
            $mpid = $l->matched_property_id ?? null;
            if ($mpid) {
                $listingByPropertyId[(int) $mpid] = $l;
            }
        }

        if (empty($listingIds)) {
            return [
                'pitches' => [], 'claims' => [], 'prospected' => [], 'presentations' => [],
                'contact_counts' => [], 'promotions' => [], 'temp_locks' => [],
            ];
        }

        $propertyIds = array_keys($listingByPropertyId);
        $listingByPropertyId = collect($listingByPropertyId);

        return [
            'pitches' => $this->loadPitches($propertyIds, $agencyId, $listingByPropertyId),
            'claims' => $this->loadClaims($listingIds, $agencyId),
            // Worked-and-closed claims (inactive + feedback recorded) — drives the
            // durable "Prospected" badge so a killed listing never reverts to
            // looking never-touched. Read regardless of is_active.
            'prospected' => $this->loadProspected($listingIds, $agencyId),
            'presentations' => $this->loadPresentations($propertyIds, $agencyId, $listingByPropertyId),
            'contact_counts' => $this->loadContactCounts($propertyIds, $agencyId, $listingByPropertyId),
            'promotions' => $this->loadPromotions($propertyIds, $agencyId),
            // Active temp pitch-locks per listing — blocks the Pitch CTA for other agents
            // while one agent has the composer open. Delegated to ProspectingClaimService
            // so all claim-lifecycle state stays in one place.
            'temp_locks' => app(\App\Services\Prospecting\ProspectingClaimService::class)
                ->loadTempLocksForListings($listingIds, $agencyId),
        ];
    }

    /**
     * Most recent pitch per matched property. One query, in-memory reduction to keep the
     * dataset small and avoid a window-function dependency.
     */
    private function loadPitches(array $propertyIds, int $agencyId, Collection $listingByPropertyId): array
    {
        if (empty($propertyIds)) return [];

        $rows = DB::table('seller_outreach_sends as s')
            ->leftJoin('seller_outreach_templates as t', 't.id', '=', 's.template_id')
            ->leftJoin('users as u', 'u.id', '=', 's.agent_id')
            ->whereIn('s.property_id', $propertyIds)
            ->where('s.agency_id', $agencyId)
            ->whereNull('s.deleted_at')
            ->select(
                's.property_id',
                's.id as send_id',
                's.contact_id',
                's.agent_id',
                's.sent_at',
                's.channel',
                's.outcome',
                's.recipient_phone_snapshot as recipient_phone',
                's.recipient_email_snapshot as recipient_email',
                't.name as template_name',
                'u.name as agent_name'
            )
            ->orderByDesc('s.sent_at')
            ->get();

        // Reduce to most-recent-per-property (first iteration of orderByDesc wins).
        $byProperty = [];
        foreach ($rows as $r) {
            if (!isset($byProperty[$r->property_id])) {
                $byProperty[$r->property_id] = $r;
            }
        }

        $sevenDaysAgo = strtotime('-7 days');
        $result = [];
        foreach ($byProperty as $propertyId => $r) {
            $listing = $listingByPropertyId->get($propertyId);
            if (!$listing) continue;
            $sentTs = $r->sent_at ? strtotime((string) $r->sent_at) : false;
            $result[(int) $listing->id] = [
                'send_id' => (int) $r->send_id,
                'contact_id' => (int) $r->contact_id,
                // Surfaced as agent_user_id for the SuggestedActionResolver's
                // owner check; underlying column is seller_outreach_sends.agent_id.
                'agent_user_id' => $r->agent_id !== null ? (int) $r->agent_id : null,
                'sent_at' => $r->sent_at,
                'channel' => $r->channel,
                'outcome' => $r->outcome,
                'recipient_phone' => $r->recipient_phone,
                'recipient_email' => $r->recipient_email,
                'template_name' => $r->template_name,
                'agent_name' => $r->agent_name,
                'is_recent' => $sentTs !== false && $sentTs > $sevenDaysAgo,
            ];
        }
        return $result;
    }

    /**
     * Active claims keyed by listing_id. Status freshness is per the seller-outreach module:
     * a claim expires 48h after its last_updated_at unless agent provides feedback.
     */
    private function loadClaims(array $listingIds, int $agencyId): array
    {
        if (empty($listingIds)) return [];

        $rows = DB::table('prospecting_claims as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('c.prospecting_listing_id', $listingIds)
            ->where('c.agency_id', $agencyId)
            ->where('c.is_active', true)
            ->whereNull('c.released_at')
            ->select(
                'c.prospecting_listing_id',
                'c.id as claim_id',
                'c.user_id',
                'c.status',
                'c.is_active',
                'c.claimed_at',
                'c.last_updated_at',
                'c.feedback_at',
                'c.flagged_at',
                'u.name as claimer_name'
            )
            ->orderByDesc('c.claimed_at')
            ->get();

        $now = time();
        $sevenDaysAgo = $now - 7 * 86400;
        $fourteenDaysAgo = $now - 14 * 86400;
        $result = [];
        foreach ($rows as $r) {
            $key = (int) $r->prospecting_listing_id;
            if (isset($result[$key])) continue; // first (most recent) wins

            $lastUpdatedTs = $r->last_updated_at ? strtotime((string) $r->last_updated_at) : false;
            $expiresAt = $lastUpdatedTs !== false ? $lastUpdatedTs + 48 * 3600 : null;
            $hoursLeft = $expiresAt !== null ? max(0, ($expiresAt - $now) / 3600) : null;

            $hasFeedback = $r->feedback_at !== null;
            // Mirror ProspectingClaim::needsReminder() against the raw row.
            $needsReminder = (bool) $r->is_active
                && $hasFeedback
                && in_array((string) $r->status, ['contacted', 'meeting_set'], true)
                && $lastUpdatedTs !== false
                && $lastUpdatedTs < $sevenDaysAgo;
            // Mirror ProspectingClaim::needsBmFlag() against the raw row.
            $needsBmFlag = (bool) $r->is_active
                && (string) $r->status === 'listing'
                && $hasFeedback
                && $lastUpdatedTs !== false
                && $lastUpdatedTs < $fourteenDaysAgo
                && $r->flagged_at === null;

            $result[$key] = [
                'claim_id' => (int) $r->claim_id,
                'user_id' => (int) $r->user_id,
                'claimer_name' => $r->claimer_name,
                'status' => $r->status,
                'is_active' => (bool) $r->is_active,
                'claimed_at' => $r->claimed_at,
                'last_updated_at' => $r->last_updated_at,
                'feedback_at' => $r->feedback_at,
                'flagged_at' => $r->flagged_at,
                'hours_left' => $hoursLeft,
                'is_expiring' => $hoursLeft !== null && $hoursLeft < 1,
                'needs_reminder' => $needsReminder,
                'needs_bm_flag' => $needsBmFlag,
            ];
        }
        return $result;
    }

    /**
     * Most-recent WORKED-AND-CLOSED claim per listing — the data behind the durable
     * "Prospected" badge. A claim is surfaced here when it is inactive (someone has
     * finished with it) AND it carries recorded feedback (feedback_at is set), i.e.
     * an agent genuinely worked it and logged an outcome (typically not_interested /
     * lost, which auto-release the claim; also covers a contacted/meeting_set claim
     * that later lapsed). Read regardless of is_active — that is the whole point: the
     * active-only loadClaims() query goes blank once feedback() deactivates the claim,
     * which is how a worked listing used to revert to looking never-touched.
     *
     * Released-without-feedback claims (manager release / pure 48h expiry with no
     * feedback) are intentionally EXCLUDED — those were deliberately returned to the
     * pool and should look available again. The view only renders this when there is
     * no active claim, so an active re-claim always takes visual precedence.
     */
    private function loadProspected(array $listingIds, int $agencyId): array
    {
        if (empty($listingIds)) return [];

        $rows = DB::table('prospecting_claims as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->whereIn('c.prospecting_listing_id', $listingIds)
            ->where('c.agency_id', $agencyId)
            ->where('c.is_active', false)
            ->whereNotNull('c.feedback_at')
            ->whereNull('c.deleted_at')
            ->select(
                'c.prospecting_listing_id',
                'c.id as claim_id',
                'c.user_id',
                'c.status',
                'c.feedback_at',
                'c.last_updated_at',
                'c.claimed_at',
                'u.name as claimer_name'
            )
            // Most recent worked outcome wins (feedback time, then claim time).
            ->orderByDesc('c.feedback_at')
            ->orderByDesc('c.claimed_at')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $key = (int) $r->prospecting_listing_id;
            if (isset($result[$key])) continue; // first (most recent) wins
            $result[$key] = [
                'claim_id'     => (int) $r->claim_id,
                'user_id'      => (int) $r->user_id,
                'claimer_name' => $r->claimer_name,
                'status'       => $r->status,
                'outcome'      => \App\Models\ProspectingClaim::humanStatus($r->status),
                'feedback_at'  => $r->feedback_at,
                'worked_at'    => $r->feedback_at ?? $r->last_updated_at ?? $r->claimed_at,
            ];
        }
        return $result;
    }

    private function loadPresentations(array $propertyIds, int $agencyId, Collection $listingByPropertyId): array
    {
        if (empty($propertyIds)) return [];

        // Defence-in-depth agency scope: even though $propertyIds is already
        // resolved from agency-scoped prospecting listings, we re-confirm via
        // a join on properties so a cross-agency presentation cannot leak
        // (e.g. if a property had moved agencies, or if presentation_id had
        // been bound by mistake).
        $rows = DB::table('presentations as p')
            ->join('properties as pr', function ($join) use ($agencyId) {
                $join->on('pr.id', '=', 'p.listing_id')
                    ->where('pr.agency_id', $agencyId)
                    ->whereNull('pr.deleted_at');
            })
            ->leftJoin('users as u', 'u.id', '=', 'p.created_by_user_id')
            ->whereIn('p.listing_id', $propertyIds)
            ->whereNull('p.deleted_at')
            ->select(
                'p.listing_id',
                'p.id as presentation_id',
                'p.created_at',
                'p.updated_at',
                'p.title',
                'u.name as creator_name'
            )
            ->orderByDesc('p.updated_at')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            if (isset($result[$r->listing_id])) continue; // most-recent wins per property
            $listing = $listingByPropertyId->get($r->listing_id);
            if (!$listing) continue;
            $result[(int) $listing->id] = [
                'presentation_id' => (int) $r->presentation_id,
                'created_at' => $r->created_at,
                'creator_name' => $r->creator_name,
                'title' => $r->title,
            ];
        }
        return $result;
    }

    private function loadContactCounts(array $propertyIds, int $agencyId, Collection $listingByPropertyId): array
    {
        if (empty($propertyIds)) return [];

        $rows = DB::table('contact_property as cp')
            ->join('contacts as c', 'c.id', '=', 'cp.contact_id')
            ->whereIn('cp.property_id', $propertyIds)
            ->where('c.agency_id', $agencyId)
            ->whereNull('c.deleted_at')
            ->select('cp.property_id', DB::raw('COUNT(DISTINCT cp.contact_id) as cnt'))
            ->groupBy('cp.property_id')
            ->get();

        $result = [];
        foreach ($rows as $r) {
            $listing = $listingByPropertyId->get($r->property_id);
            if (!$listing) continue;
            $result[(int) $listing->id] = (int) $r->cnt;
        }
        return $result;
    }

    /**
     * Confirms each matched_property_id resolves to a live properties row in the agency.
     * Returns map keyed by property_id (NOT listing_id) for direct view lookup against
     * $listing->matched_property_id.
     */
    private function loadPromotions(array $propertyIds, int $agencyId): array
    {
        if (empty($propertyIds)) return [];

        $rows = DB::table('properties')
            ->whereIn('id', $propertyIds)
            ->where('agency_id', $agencyId)
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        $map = [];
        foreach ($rows as $id) $map[(int) $id] = true;
        return $map;
    }
}
