<?php

declare(strict_types=1);

namespace App\Services\Prospecting;

use App\Models\ProspectingListing;
use App\Models\SuggestedActionThresholds;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Single source of truth for the F.2 "action preset" filters used by BOTH the
 * MIC Work tab list AND the "This Week" hero tiles.
 *
 * The controller renders the list through applyPreset(); ThisWeekTileBuilder
 * counts through countForPreset(), which runs the SAME query. So a tile's
 * headline number always equals what clicking its link shows — a tile can never
 * advertise "7 new" and land on 0 again (the 2026-07-07 bug).
 */
class ProspectingActionPresetService
{
    /**
     * The default canvass pool a bare Work-tab link lands on: agency-scoped,
     * not-yet-matched (un-mandated) stock. Soft-delete scope is applied by the
     * model. Mirrors MarketIntelligenceController::work()'s base query +
     * applyInStockFilter() default (no manager include_in_stock override).
     */
    public function canvassBaseQuery(int $agencyId): Builder
    {
        return ProspectingListing::query()
            ->where('agency_id', $agencyId)
            ->whereNull('matched_property_id');
    }

    /**
     * Apply an action preset as additional WHERE clauses. This is the authority
     * for what each preset means; MarketIntelligenceController::applyActionPreset
     * delegates here so the list and the tile counts can never diverge.
     *
     *   pitch_now_high → no active claim + strong-tier count >= high_value_strong_min
     *   pitch_now      → no active claim + strong-tier count in [1, high_value_strong_min - 1]
     *   log_outcomes   → matched_property had a pitch from $viewer in the overdue window, no outcome
     *   my_claims      → active claim owned by $viewer
     *   expiring       → active claim owned by $viewer, no feedback, hours_left below threshold
     *   new_today      → listings first seen within thresholds.new_listing_lookback_days
     *
     * Unknown presets are logged and the query returned unfiltered (safe fallback).
     */
    public function applyPreset(
        Builder $query,
        ?string $preset,
        int $agencyId,
        ?int $viewerId,
        SuggestedActionThresholds $thresholds,
    ): Builder {
        if (!$preset) {
            return $query;
        }

        $strongMin = (int) $thresholds->high_value_strong_min;

        switch ($preset) {
            case 'pitch_now_high':
                return $query->whereDoesntHave('activeClaim')
                    ->whereIn('id', DB::table('prospecting_buyer_matches')
                        ->where('agency_id', $agencyId)
                        ->whereNull('dismissed_at')
                        ->where('score', '>=', 80)
                        ->groupBy('prospecting_listing_id')
                        ->havingRaw('COUNT(*) >= ?', [$strongMin])
                        ->select('prospecting_listing_id'));

            case 'pitch_now':
                return $query->whereDoesntHave('activeClaim')
                    ->whereIn('id', DB::table('prospecting_buyer_matches')
                        ->where('agency_id', $agencyId)
                        ->whereNull('dismissed_at')
                        ->where('score', '>=', 80)
                        ->groupBy('prospecting_listing_id')
                        ->havingRaw('COUNT(*) >= 1 AND COUNT(*) < ?', [$strongMin])
                        ->select('prospecting_listing_id'));

            case 'log_outcomes':
                if ($viewerId === null) return $query->whereRaw('1 = 0');
                $stale = now()->subDays($thresholds->outcome_stale_days);
                $overdue = now()->subDays($thresholds->outcome_overdue_days);
                return $query->whereIn('matched_property_id', DB::table('seller_outreach_sends')
                    ->where('agency_id', $agencyId)
                    ->where('agent_id', $viewerId)
                    ->whereNull('deleted_at')
                    ->where(function ($q) {
                        $q->whereNull('outcome')->orWhere('outcome', 'sent');
                    })
                    ->whereBetween('sent_at', [$stale, $overdue])
                    ->select('property_id'));

            case 'my_claims':
                if ($viewerId === null) return $query->whereRaw('1 = 0');
                return $query->whereHas('activeClaim', fn ($q) => $q->where('user_id', $viewerId));

            case 'expiring':
                if ($viewerId === null) return $query->whereRaw('1 = 0');
                // hours_left < expiry_warning_hours means the claim's
                // last_updated_at + 48h is less than now + warning hours,
                // i.e. last_updated_at is older than (now - (48 - warning)).
                $hoursOlderThan = 48 - (int) $thresholds->expiry_warning_hours;
                return $query->whereHas('activeClaim', function ($q) use ($viewerId, $hoursOlderThan) {
                    $q->where('user_id', $viewerId)
                      ->whereNull('feedback_at')
                      ->where('last_updated_at', '<=', now()->subHours($hoursOlderThan));
                });

            case 'new_today':
                // Listings first seen within the agency's configured lookback
                // window (default 1 day ≈ today). max(1, ...) guards a blank save.
                $lookbackDays = max(1, (int) $thresholds->new_listing_lookback_days);
                return $query->where('first_seen_at', '>=', now()->subDays($lookbackDays));
        }

        Log::warning('ProspectingActionPresetService::applyPreset received unknown preset', [
            'preset' => $preset, 'agency_id' => $agencyId, 'viewer_id' => $viewerId,
        ]);
        return $query;
    }

    /** Count exactly what a `?action_preset=X` Work-tab link would show. */
    public function countForPreset(int $agencyId, ?int $viewerId, string $preset, SuggestedActionThresholds $thresholds): int
    {
        return $this->groupedCount(
            $this->applyPreset($this->canvassBaseQuery($agencyId), $preset, $agencyId, $viewerId, $thresholds)
        );
    }

    /** Count exactly what a `?suburb=X&bedrooms_exact=Y` Work-tab link would show. */
    public function countForSuburbBedrooms(int $agencyId, string $suburb, int $bedrooms): int
    {
        return $this->groupedCount(
            $this->canvassBaseQuery($agencyId)->where('suburb', $suburb)->where('bedrooms', $bedrooms)
        );
    }

    /**
     * Count the ROWS an agent actually sees, not raw listings. work() collapses
     * cross-listed duplicates via groupBy(property_group_id ?? 'single_'.id)
     * before rendering, so a raw count over-reports by the number of cross-listed
     * duplicates. Mirror that grouping so the tile number equals the list length.
     */
    private function groupedCount(Builder $query): int
    {
        return $query->get(['id', 'property_group_id'])
            ->groupBy(fn ($l) => $l->property_group_id ?? 'single_' . $l->id)
            ->count();
    }
}
