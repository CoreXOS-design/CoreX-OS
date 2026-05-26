<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use Illuminate\Support\Facades\DB;

/**
 * Smart Filter Presets for the Market Intelligence header.
 *
 * Each preset answers "what should I do today?" in one click. The service
 * computes counts (fast, indexed queries) AND returns the WHERE-clause
 * criteria that, when applied to the listings table, surfaces the matching rows.
 *
 * Counts only — drill-down filtering is layered onto the existing legacy
 * paginated $listings query in ProspectingController::index() so the existing
 * filter pipeline stays the source of truth.
 *
 * Multi-tenancy: every query takes $agencyId + $userId explicitly.
 *
 * Spec: VS Code build prompt 2026-05-14 (Market Intelligence interactive filters).
 */
final class SmartFilterPresetService
{
    public function __construct(
        private readonly ProspectingConfigurationService $config,
    ) {}

    /**
     * Returns 4 preset cards keyed by slug.
     *
     * Each card: ['icon','label','description','count','visible_to'].
     * visible_to = 'manager' for BM/admin-only presets; caller filters by permission.
     */
    public function presetsFor(int $agencyId, int $userId): array
    {
        $strongMinScore = (int) ($this->config->buyerMatchTiers($agencyId)['strong_min_score'] ?? 80);

        return [
            'pitch-today' => [
                'icon'        => '🔥',
                'label'       => 'Pitch Today',
                'description' => 'Unclaimed listings with at least one strong-tier buyer match and no pitch sent in 30 days.',
                'count'       => $this->countPitchToday($agencyId, $strongMinScore),
                'visible_to'  => 'all',
            ],
            'follow-up' => [
                'icon'        => '⏰',
                'label'       => 'Follow Up',
                'description' => 'Your claims in "contacted" status with no update in 5+ days.',
                'count'       => $this->countFollowUp($agencyId, $userId),
                'visible_to'  => 'all',
            ],
            'buyer-heat' => [
                'icon'        => '🎯',
                'label'       => 'Buyer Heat',
                'description' => 'Your buyer wishlists with no strong matches and no engagement in 14+ days.',
                'count'       => $this->countBuyerHeat($agencyId, $userId, $strongMinScore),
                'visible_to'  => 'all',
            ],
            'stale-claims' => [
                'icon'        => '🚨',
                'label'       => 'Stale Claims',
                'description' => 'Agency-wide claims in "contacted" status with no update in 14+ days.',
                'count'       => $this->countStaleClaims($agencyId),
                'visible_to'  => 'manager',
            ],
        ];
    }

    /**
     * 🔥 Pitch Today — listings where:
     *   - has ≥1 buyer match at the agency's strong-tier threshold or above
     *   - has NO active prospecting_claim
     *   - has NO active prospecting_pitch_lock (concurrent composer)
     *   - has NO seller_outreach_send in the last 30 days for the matched property
     */
    public function countPitchToday(int $agencyId, int $strongMinScore): int
    {
        return (int) DB::table('prospecting_listings as pl')
            ->where('pl.agency_id', $agencyId)
            ->whereNull('pl.deleted_at')
            ->whereExists(function ($q) use ($agencyId, $strongMinScore) {
                $q->select(DB::raw(1))
                  ->from('prospecting_buyer_matches as pbm')
                  ->whereColumn('pbm.prospecting_listing_id', 'pl.id')
                  ->where('pbm.agency_id', $agencyId)
                  ->whereNull('pbm.dismissed_at')
                  ->where('pbm.score', '>=', $strongMinScore);
            })
            ->whereNotExists(function ($q) use ($agencyId) {
                $q->select(DB::raw(1))
                  ->from('prospecting_claims as pc')
                  ->whereColumn('pc.prospecting_listing_id', 'pl.id')
                  ->where('pc.agency_id', $agencyId)
                  ->where('pc.is_active', true)
                  ->whereNull('pc.released_at');
            })
            ->whereNotExists(function ($q) use ($agencyId) {
                $q->select(DB::raw(1))
                  ->from('prospecting_pitch_locks as ppl')
                  ->whereColumn('ppl.prospecting_listing_id', 'pl.id')
                  ->where('ppl.agency_id', $agencyId)
                  ->whereNull('ppl.released_at')
                  ->where('ppl.expires_at', '>', now());
            })
            ->whereNotExists(function ($q) use ($agencyId) {
                $q->select(DB::raw(1))
                  ->from('seller_outreach_sends as sos')
                  ->whereColumn('sos.property_id', 'pl.matched_property_id')
                  ->where('sos.agency_id', $agencyId)
                  ->whereNull('sos.deleted_at')
                  ->where('sos.sent_at', '>', now()->subDays(30));
            })
            ->count();
    }

    /**
     * ⏰ Follow Up — current user's "contacted" claims that haven't been updated in 5+ days.
     */
    public function countFollowUp(int $agencyId, int $userId): int
    {
        return (int) DB::table('prospecting_claims')
            ->where('agency_id', $agencyId)
            ->where('user_id', $userId)
            ->where('status', 'contacted')
            ->where('is_active', true)
            ->whereNull('released_at')
            ->where('last_updated_at', '<', now()->subDays(5))
            ->count();
    }

    /**
     * 🎯 Buyer Heat — current user's buyer wishlists going cold:
     *   - active wishlist owned (created OR updated) by the user
     *   - no engagement in 14+ days
     *   - no strong-tier match scored in the last 14 days
     */
    public function countBuyerHeat(int $agencyId, int $userId, int $strongMinScore): int
    {
        return (int) DB::table('contact_matches as cm')
            ->where('cm.agency_id', $agencyId)
            ->where('cm.status', 'active')
            ->where(function ($q) use ($userId) {
                $q->where('cm.created_by_user_id', $userId)
                  ->orWhere('cm.updated_by_user_id', $userId);
            })
            ->whereNull('cm.deleted_at')
            ->where(function ($q) {
                $q->whereNull('cm.last_engaged_at')
                  ->orWhere('cm.last_engaged_at', '<', now()->subDays(14));
            })
            ->whereNotExists(function ($q) use ($agencyId, $strongMinScore) {
                $q->select(DB::raw(1))
                  ->from('prospecting_buyer_matches as pbm')
                  ->whereColumn('pbm.contact_id', 'cm.contact_id')
                  ->where('pbm.agency_id', $agencyId)
                  ->whereNull('pbm.dismissed_at')
                  ->where('pbm.score', '>=', $strongMinScore)
                  ->where('pbm.matched_at', '>', now()->subDays(14));
            })
            ->count();
    }

    /**
     * 🚨 Stale Claims — agency-wide claims in `contacted` status not updated in 14+ days.
     * Surfaces only to BMs/admins (caller checks the permission).
     */
    public function countStaleClaims(int $agencyId): int
    {
        return (int) DB::table('prospecting_claims')
            ->where('agency_id', $agencyId)
            ->where('status', 'contacted')
            ->where('is_active', true)
            ->whereNull('released_at')
            ->where('last_updated_at', '<', now()->subDays(14))
            ->count();
    }

    /**
     * Apply the preset's WHERE criteria to the listings paginator query.
     * Returns the same query, mutated.
     *
     * This is the bridge that makes the existing legacy $listings query honor
     * preset filtering, so the chips at the top control the table at the bottom.
     */
    public function applyPresetToListings($query, string $preset, int $agencyId, int $userId)
    {
        $strongMinScore = (int) ($this->config->buyerMatchTiers($agencyId)['strong_min_score'] ?? 80);

        return match ($preset) {
            'pitch-today' => $query
                ->whereExists(function ($q) use ($agencyId, $strongMinScore) {
                    $q->select(DB::raw(1))
                      ->from('prospecting_buyer_matches as pbm')
                      ->whereColumn('pbm.prospecting_listing_id', 'prospecting_listings.id')
                      ->where('pbm.agency_id', $agencyId)
                      ->whereNull('pbm.dismissed_at')
                      ->where('pbm.score', '>=', $strongMinScore);
                })
                ->whereNotExists(function ($q) use ($agencyId) {
                    $q->select(DB::raw(1))
                      ->from('prospecting_claims as pc')
                      ->whereColumn('pc.prospecting_listing_id', 'prospecting_listings.id')
                      ->where('pc.agency_id', $agencyId)
                      ->where('pc.is_active', true)
                      ->whereNull('pc.released_at');
                })
                ->whereNotExists(function ($q) use ($agencyId) {
                    $q->select(DB::raw(1))
                      ->from('prospecting_pitch_locks as ppl')
                      ->whereColumn('ppl.prospecting_listing_id', 'prospecting_listings.id')
                      ->where('ppl.agency_id', $agencyId)
                      ->whereNull('ppl.released_at')
                      ->where('ppl.expires_at', '>', now());
                })
                ->whereNotExists(function ($q) use ($agencyId) {
                    $q->select(DB::raw(1))
                      ->from('seller_outreach_sends as sos')
                      ->whereColumn('sos.property_id', 'prospecting_listings.matched_property_id')
                      ->where('sos.agency_id', $agencyId)
                      ->whereNull('sos.deleted_at')
                      ->where('sos.sent_at', '>', now()->subDays(30));
                }),

            'follow-up' => $query->whereExists(function ($q) use ($agencyId, $userId) {
                $q->select(DB::raw(1))
                  ->from('prospecting_claims as pc')
                  ->whereColumn('pc.prospecting_listing_id', 'prospecting_listings.id')
                  ->where('pc.agency_id', $agencyId)
                  ->where('pc.user_id', $userId)
                  ->where('pc.status', 'contacted')
                  ->where('pc.is_active', true)
                  ->whereNull('pc.released_at')
                  ->where('pc.last_updated_at', '<', now()->subDays(5));
            }),

            'stale-claims' => $query->whereExists(function ($q) use ($agencyId) {
                $q->select(DB::raw(1))
                  ->from('prospecting_claims as pc')
                  ->whereColumn('pc.prospecting_listing_id', 'prospecting_listings.id')
                  ->where('pc.agency_id', $agencyId)
                  ->where('pc.status', 'contacted')
                  ->where('pc.is_active', true)
                  ->whereNull('pc.released_at')
                  ->where('pc.last_updated_at', '<', now()->subDays(14));
            }),

            // 'buyer-heat' filters buyer wishlists rather than listings — surfaced as a
            // count card but doesn't reshape the listings table. The card click sets the
            // preset URL param; the legacy query returns its normal output. A future
            // build can route this to a buyer-pipeline view instead of this listings page.
            default => $query,
        };
    }
}
