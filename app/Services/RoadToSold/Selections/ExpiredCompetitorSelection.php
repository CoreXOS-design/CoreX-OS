<?php

namespace App\Services\RoadToSold\Selections;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;

/**
 * The tenth selection - expired competitor listings (Johan, 20 Oct 2026; road-to-sold.md 22.6).
 *
 * A competitor's advert qualifies ONLY when CoreX holds a dated signal that it ended: the portal reported it WITHDRAWN and stamped when
 * (prospecting_listings.portal_status = 'withdrawn' + portal_status_changed_at - dated on every such row on QA1). No listing carries a mandate
 * end date, so nothing is guessed from one. It must have happened inside the agency's look-back (default 30 days), no other advert for the
 * same property may be live, and the property may not be sold / under offer on any portal. Most recently withdrawn first.
 *
 * The line reads "Expired with <agency> <date>" (Johan's wording, 27 Oct); the structured facts keep what CoreX actually knows - the portal said
 * WITHDRAWN on that date - so a withdrawal that was a relist or a change of mind can still be told apart.
 */
final class ExpiredCompetitorSelection extends MicPoolSelection
{
    public function key(): string
    {
        return 'expired_competitor';
    }

    public function label(): string
    {
        return 'Expired competitor listings';
    }

    /** A withdrawn advert is no longer live: the base pool must not insist on is_active = 1. */
    protected function activeOnly(): bool
    {
        return false;
    }

    protected function filter(Builder $q, SelectionContext $ctx): void
    {
        $day = Carbon::instance($ctx->date)->startOfDay();
        $cut = $day->copy()->subDays(max(1, $ctx->settings->expiredCompetitorLookbackDays))->toDateTimeString();

        $q->where('pl.portal_status', 'withdrawn')
          ->whereNotNull('pl.portal_status_changed_at')
          ->where('pl.portal_status_changed_at', '>=', $cut)
          ->where('pl.portal_status_changed_at', '<=', $day->copy()->endOfDay()->toDateTimeString())
          // another advert for the same property is live: it was relisted, not expired
          ->whereRaw('NOT EXISTS (SELECT 1 FROM prospecting_listings pa WHERE pa.tracked_property_id = pl.tracked_property_id
                AND pa.id <> pl.id AND pa.is_active = 1 AND pa.deleted_at IS NULL AND pa.agency_id = pl.agency_id)')
          // sold / under offer anywhere: not stock to win
          ->whereRaw("NOT EXISTS (SELECT 1 FROM prospecting_listings pb WHERE pb.tracked_property_id = pl.tracked_property_id
                AND pb.deleted_at IS NULL AND pb.agency_id = pl.agency_id AND pb.portal_status IN ('sold', 'under_offer'))");
        $q->addSelect('pl.portal_status_changed_at as withdrawn_at', 'pl.agency_name as listing_agency');
    }

    protected function order(Builder $q, SelectionContext $ctx): void
    {
        $q->orderByDesc('withdrawn_at');
    }

    protected function explain(object $row, SelectionContext $ctx): array
    {
        return \App\Services\RoadToSold\Reasons\ReasonLine::expiredCompetitor($row->listing_agency ?? null, $row->portal_source === 'pp' ? 'Private Property' : 'Property24', $row->withdrawn_at);
    }

    protected function reason(object $row, SelectionContext $ctx): string
    {
        return $this->explain($row, $ctx)['line'];
    }
}
