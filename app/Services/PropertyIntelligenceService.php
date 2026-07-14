<?php

namespace App\Services;

use App\Models\CommandCenter\CalendarEvent;
use App\Models\CommandCenter\CalendarEventFeedback;
use App\Models\Property;
use App\Services\Matching\MatchingService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Aggregates calendar feedback, portal data, buyer signals, compliance,
 * and auto-derived recommendations into the Property Intelligence Hub.
 */
class PropertyIntelligenceService
{
    /**
     * Chronological activity timeline for a property.
     */
    public function getActivityTimeline(int $propertyId, int $limit = 50): Collection
    {
        // Viewings + feedback events
        $events = CalendarEvent::withoutGlobalScopes()
            ->whereHas('linkedProperties', fn($q) => $q->where('properties.id', $propertyId))
            ->with('linkedContacts')
            ->orderByDesc('event_date')
            ->limit($limit)
            ->get()
            ->map(fn($e) => [
                'type' => 'event',
                'date' => $e->event_date,
                'title' => $e->title,
                'category' => $e->category,
                'status' => $e->status,
                'agent' => $e->user_id,
                'event_id' => $e->id,
            ]);

        return $events->sortByDesc('date')->values();
    }

    /**
     * Portal performance metrics for a property (last N days).
     *
     * Real Property24 engagement, summed from property_portal_metrics (populated
     * daily by P24StatsService from the P24 v53 statistics API). See
     * .ai/specs/portal-metrics.md.
     *
     * Private Property exposes NO views/statistics API (only lifecycle events +
     * webhook leads), so there is nothing to sum for PP — `pp_supported` is false
     * and the UI states this honestly rather than implying a real zero. The
     * `views` key stays for back-compat and equals the P24 view total.
     */
    public function getPortalPerformance(int $propertyId, int $rangeDays = 30): array
    {
        $since = now()->subDays($rangeDays)->format('Y-m-d');

        $aggFor = fn (string $portal) => \App\Models\PropertyPortalMetric::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('portal', $portal)
            ->where('metric_date', '>=', $since)
            ->selectRaw('COALESCE(SUM(view_count),0) AS views')
            ->selectRaw('COALESCE(SUM(alert_count),0) AS favourites')
            ->selectRaw('COALESCE(SUM(total_leads),0) AS enquiries')
            ->selectRaw('COUNT(*) AS day_rows')
            ->first();

        $agg   = $aggFor(\App\Models\PropertyPortalMetric::PORTAL_P24);
        $ppAgg = $aggFor(\App\Models\PropertyPortalMetric::PORTAL_PP);

        $views      = (int) ($agg->views ?? 0);
        $favourites = (int) ($agg->favourites ?? 0);
        $enquiries  = (int) ($agg->enquiries ?? 0);
        $ppViews    = (int) ($ppAgg->views ?? 0);
        $ppEnq      = (int) ($ppAgg->enquiries ?? 0);
        $ppHasData  = (int) ($ppAgg->day_rows ?? 0) > 0;

        return [
            'views' => $views,
            'p24_views' => $views,
            'favourites' => $favourites,
            'enquiries' => $enquiries,
            'total' => $views + $favourites + $enquiries,
            'range_days' => $rangeDays,
            // PP stats now flow (AT-201) — supported once the snapshot has landed a day.
            'pp_supported' => $ppHasData,
            'pp_views' => $ppViews,
            'pp_enquiries' => $ppEnq,
            'has_data' => (int) ($agg->day_rows ?? 0) > 0 || $ppHasData,
        ];
    }

    /**
     * Daily P24 engagement series for the property, oldest-first, over the last
     * $days (capped at 180 — P24's stat retention ceiling). Every calendar day in
     * the span is emitted (zero-filled) so the chart x-axis is continuous. The
     * frontend slices this by the selected range (30d / 90d / all); "leads" here
     * is the per-day P24 lead COUNT from the stats API (available historically),
     * distinct from the Portal Leads table's individual records (last ~30 days).
     * See .ai/specs/portal-metrics.md.
     */
    public function getPortalEngagementSeries(int $propertyId, int $days = 180): array
    {
        $days  = max(1, min($days, 180));
        $start = now()->startOfDay()->subDays($days);
        $end   = now()->startOfDay();

        $fetch = fn (string $portal) => \App\Models\PropertyPortalMetric::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('portal', $portal)
            ->where('metric_date', '>=', $start->format('Y-m-d'))
            ->orderBy('metric_date')
            ->get(['metric_date', 'view_count', 'total_leads'])
            ->keyBy(fn ($r) => $r->metric_date->format('Y-m-d'));

        $rows   = $fetch(\App\Models\PropertyPortalMetric::PORTAL_P24);
        // Private Property engagement now accumulates from the day pp_stats_pull is
        // switched on (no historical backfill) — plotted as its own series (AT-201).
        $ppRows = $fetch(\App\Models\PropertyPortalMetric::PORTAL_PP);

        $series = [];
        for ($d = $start->copy(); $d < $end; $d->addDay()) {
            $key = $d->format('Y-m-d');
            $row   = $rows->get($key);
            $ppRow = $ppRows->get($key);
            $series[] = [
                'date'     => $key,
                'views'    => (int) ($row->view_count ?? 0),
                'leads'    => (int) ($row->total_leads ?? 0),
                'pp_views' => (int) ($ppRow->view_count ?? 0),
                'pp_leads' => (int) ($ppRow->total_leads ?? 0),
            ];
        }

        return [
            'series'      => $series,
            'has_data'    => $rows->isNotEmpty(),
            'pp_has_data' => $ppRows->isNotEmpty(),
            'max_days'    => $days,
        ];
    }

    /**
     * Buyers whose wishlist genuinely matches this property, scored by the
     * CANONICAL engine (AT-73).
     *
     * Previously this stamped a hardcoded match_score=75 onto the first 10
     * is_buyer contacts in the agency with NO actual matching — a fabricated
     * count that lied on the Intelligence tab (and contradicted the Core
     * Matches tab on the same page). It now delegates to the SAME engine the
     * property-page Core Matches tab uses — MatchingService::matchesForProperty
     * (Engine A / "Path 1") — so the Intelligence tab shows the real buyers at
     * their real % and tier.
     *
     * Inherited automatically from the canonical engine:
     *   - AT-71 countable gate (empty / below-bar wishlists are excluded —
     *     matchesForProperty calls ->countable()), and
     *   - the same hard filters (listing_type / price / beds / suburb / status).
     *
     * Definition of "a buyer match" here = the canonical floor: score >=
     * MatchingService::MIN_SCORE_TO_DISPLAY (50) → tierFor() != null. A buyer
     * with several matching wishlists is counted ONCE (best score), so the card
     * is an honest BUYER count, not a wishlist count.
     *
     * @return Collection<int,array{id:int,name:string,state:?string,last_activity:mixed,match_score:int,tier:?string}>
     */
    public function getBuyerInterestSignals(int $propertyId): Collection
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) {
            return collect();
        }

        return app(MatchingService::class)->matchesForProperty($property)
            // Canonical floor — below 50 is "not a match" (tierFor() === null).
            ->filter(fn ($m) => (int) $m->match_score >= MatchingService::MIN_SCORE_TO_DISPLAY)
            // One row per buyer — keep their best-scoring wishlist.
            ->groupBy('contact_id')
            ->map(fn (Collection $group) => $group->sortByDesc('match_score')->first())
            ->map(function ($m) {
                $contact = $m->contact;
                $score   = (int) $m->match_score;

                return [
                    'id'            => (int) $m->contact_id,
                    'name'          => $contact
                        ? trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''))
                        : 'Buyer',
                    'state'         => $contact?->buyer_state,
                    'last_activity' => $contact?->last_activity_at,
                    'match_score'   => $score,
                    'tier'          => MatchingService::tierFor($score),
                ];
            })
            ->sortByDesc('match_score')
            ->values();
    }

    /**
     * Aggregated feedback metrics for a property.
     * Joins through calendar_event_links since feedback.property_id may be NULL.
     */
    /**
     * @param bool $excludeInternalOnly When true, filters out internal_only feedback (for seller-facing surfaces)
     */
    public function getFeedbackRollup(int $propertyId, bool $excludeInternalOnly = false): array
    {
        // Find all events linked to this property
        $eventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('linkable_id', $propertyId)
            ->where('role', 'subject_property')
            ->pluck('calendar_event_id');

        // Get feedback for those events (or directly linked to this property)
        $feedback = CalendarEventFeedback::where(function ($q) use ($propertyId, $eventIds) {
            $q->where('property_id', $propertyId)
              ->orWhereIn('calendar_event_id', $eventIds);
        })->whereNotNull('captured_at')
          ->when($excludeInternalOnly, fn ($q) => $q->where('visibility', '!=', 'internal_only'))
          ->get();

        $viewingCount = $feedback->unique('calendar_event_id')->count();
        $allConcerns = $feedback->pluck('concern_option_ids')->flatten()->filter()->countBy();
        $outcomes = $feedback->pluck('outcome_option_id')->filter()->countBy();

        return [
            'total_viewings' => $viewingCount,
            'total_feedback_rows' => $feedback->count(),
            'top_concerns' => $allConcerns->sortDesc()->take(5)->toArray(),
            'outcome_distribution' => $outcomes->toArray(),
        ];
    }

    /**
     * Detailed viewing + feedback rows for a property (recent first, limit 20).
     */
    public function getRecentViewings(int $propertyId, int $limit = 20, bool $excludeInternalOnly = false): \Illuminate\Support\Collection
    {
        $eventIds = DB::table('calendar_event_links')
            ->where('linkable_type', 'App\\Models\\Property')
            ->where('linkable_id', $propertyId)
            ->where('role', 'subject_property')
            ->pluck('calendar_event_id');

        if ($eventIds->isEmpty()) return collect();

        $events = CalendarEvent::withoutGlobalScopes()
            ->whereIn('id', $eventIds)
            ->orderByDesc('event_date')
            ->limit($limit)
            ->get();

        $feedbackQuery = DB::table('calendar_event_feedback')
            ->whereIn('calendar_event_id', $eventIds);
        if ($excludeInternalOnly) {
            $feedbackQuery->where('visibility', '!=', 'internal_only');
        }
        $feedback = $feedbackQuery->get()->groupBy('calendar_event_id');

        $agents = \App\Models\User::withoutGlobalScopes()
            ->whereIn('id', $events->pluck('user_id')->unique()->filter())
            ->pluck('name', 'id');

        $outcomeLabels = DB::table('agency_feedback_options')
            ->where('category', 'outcome')
            ->pluck('label', 'id');

        // Resolve buyer contacts for each event. CAL-7 Class 3 — dropped the
        // ['buyer_contact','attendee'] whitelist; on staging legacy/missing-
        // config events save links with other roles (or NULL), and they
        // were silently absent from the property-page feedback list.
        // linkable_type=Contact is sufficient scoping.
        $buyerLinks = DB::table('calendar_event_links')
            ->whereIn('calendar_event_id', $eventIds)
            ->where('linkable_type', 'App\\Models\\Contact')
            ->get()
            ->groupBy('calendar_event_id');

        $contactIds = $buyerLinks->flatten()->pluck('linkable_id')->unique();
        $contacts = \App\Models\Contact::withoutGlobalScopes()
            ->whereIn('id', $contactIds)
            ->get(['id', 'first_name', 'last_name'])
            ->keyBy('id');

        return $events->map(function ($ev) use ($feedback, $agents, $outcomeLabels, $buyerLinks, $contacts) {
            $fbs = $feedback->get($ev->id, collect());
            $buyers = ($buyerLinks->get($ev->id, collect()))->map(function ($bl) use ($contacts) {
                $c = $contacts->get($bl->linkable_id);
                return $c ? ['id' => $c->id, 'name' => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''))] : null;
            })->filter()->values();

            return [
                'event_id' => $ev->id,
                'event_date' => $ev->event_date,
                'title' => $ev->title,
                'agent_name' => $agents->get($ev->user_id, 'Unknown'),
                'buyers' => $buyers,
                'feedback' => $fbs->map(fn($fb) => [
                    'outcome_label' => $outcomeLabels->get($fb->outcome_option_id),
                    'seller_notes' => $fb->seller_visible_notes,
                    'internal_notes' => $fb->internal_notes,
                    'captured_at' => $fb->captured_at,
                ])->values(),
            ];
        });
    }

    /**
     * Auto-derived agent recommendations based on feedback patterns.
     */
    public function getAgentRecommendations(int $propertyId): Collection
    {
        return DB::table('property_recommendations')
            ->where('property_id', $propertyId)
            ->whereNull('dismissed_at')
            ->whereNull('actioned_at')
            ->orderByDesc('generated_at')
            ->get();
    }

    /**
     * Similar listings in the same area for comparison.
     */
    public function getComparableListings(int $propertyId, int $limit = 5): Collection
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) return collect();

        return Property::withoutGlobalScopes()
            ->where('id', '!=', $propertyId)
            ->where('agency_id', $property->agency_id)
            ->whereNull('deleted_at')
            // Comparables must be the SAME listing type as the subject — a
            // rental (or commercial letting) is never a comparable for a sale
            // and vice-versa. Without this a "Restaurant to let" surfaced as a
            // comp for a residential sale (its monthly rent read as a price).
            // Legacy rows with a NULL listing_type default to sale.
            ->where(function ($q) use ($property) {
                $subjectType = $property->listing_type ?? 'sale';
                $q->where('listing_type', $subjectType);
                if ($subjectType === 'sale') {
                    $q->orWhereNull('listing_type');
                }
            })
            ->when($property->suburb, fn($q) => $q->where('suburb', $property->suburb))
            ->orderByDesc('published_at')
            ->limit($limit)
            ->get(['id', 'title', 'price', 'suburb', 'published_at'])
            ->map(fn($p) => [
                'id' => $p->id,
                'title' => $p->title,
                'price' => $p->price,
                'suburb' => $p->suburb,
                // Days on market = days since the listing went ON market. Prefer
                // listed_date (the real listing date); published_at is only set when
                // a property is pushed to the HFC website, so it is null for portal-
                // only listings and wrongly rendered "—" (AT-200). Fall back through
                // syndication-activation → created_at so a listed property always
                // shows a figure.
                'days_on_market' => ($dom = $p->listed_date ?? $p->p24_activated_at ?? $p->pp_activated_at ?? $p->published_at ?? $p->created_at)
                    ? \App\Support\HumanDiff::daysBetween($dom) : null,
            ]);
    }

    /**
     * Presentations linked to a property + their snapshots.
     */
    public function getPresentations(int $propertyId, bool $sellerView = false): Collection
    {
        $query = DB::table('presentations')
            ->where('listing_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at');

        if ($sellerView) {
            $query->where('status', 'finalized'); // Seller only sees finalized
            $query->limit(1); // Most recent only
        }

        return $query->get(['id', 'title', 'status', 'created_at', 'created_by_user_id', 'asking_price_inc']);
    }

    /**
     * Snapshot history for a property (market position over time).
     */
    public function getSnapshotHistory(int $propertyId): Collection
    {
        return \App\Models\PropertyPresentationSnapshot::where('property_id', $propertyId)
            ->orderByDesc('generated_at')
            ->limit(10)
            ->get();
    }

    /**
     * Market position for the property — computed LIVE (AT-214).
     *
     * This previously read the most recent PropertyPresentationSnapshot, which
     * FROZE recommended price / area average / comparable count at generation
     * time and then drifted from reality — agents saw stale Intelligence figures
     * that disagreed with the live CMA coverage. Every figure now derives from a
     * live canonical, so the property page, the seller live link and the client
     * insights API all read the same current numbers. Same shape as before, so
     * callers are unchanged; `snapshot_date` is now "today" and `is_live` is set.
     */
    public function getLatestMarketPosition(int $propertyId): ?array
    {
        $property = Property::withoutGlobalScopes()->find($propertyId);
        if (!$property) return null;

        $mds             = app(\App\Services\MarketDataSnapshotService::class);
        $comparableSales = $mds->getComparableSales($propertyId);
        $areaAvg         = $mds->calculateAreaAverages($property->suburb);
        $recommend       = $mds->calculateRecommendedPrice($property, $comparableSales);

        // Property-level comparable count = the canonical coverage union
        // (deals + MIC pool + sold comps, deduped) — one truth with the CMA
        // coverage badge shown when generating a presentation. A coverage-scoring
        // failure must never 500 the (public) seller page — degrade to the live
        // sold-comp count, never a frozen snapshot value.
        try {
            $compCount = (int) (app(\App\Services\Presentations\CmaCoverageService::class)
                ->scoreForProperty($property)['comp_count'] ?? 0);
        } catch (\Throwable $e) {
            report($e);
            $compCount = $comparableSales->count();
        }

        // Nothing meaningful to show → keep the card hidden (blade @if guard).
        if ($recommend === null && empty($areaAvg['avg_price']) && $compCount === 0) {
            return null;
        }

        $domAnchor = $property->listed_date ?? $property->p24_activated_at
            ?? $property->pp_activated_at ?? $property->published_at ?? $property->created_at;
        $dom = $domAnchor ? (int) round(abs($domAnchor->diffInDays(now()))) : null;

        return [
            'recommended_price'      => $recommend,
            'days_on_market'         => $dom,
            'area_avg_price'         => $areaAvg['avg_price'] ?? null,
            'area_avg_dom'           => $areaAvg['avg_dom'] ?? null,
            'snapshot_date'          => now()->toDateString(),
            'comparable_sales_count' => $compCount,
            'is_live'                => true,
        ];
    }

    /**
     * CMAInfo OCR output extracted from the latest presentation linked to this property.
     *
     * Reads presentation_fields.final_value (falling back to extracted_value) for the
     * subject.*, municipal.*, and cma.* keys produced by DocumentExtractor.
     *
     * Why: presentation OCR already extracts erf, GPS, municipal valuation, CMA bands,
     * historical sale data — but it stays locked inside the presentation. Surfacing it
     * here turns the existing OCR pipeline into a property-pillar enrichment surface
     * without writing any new ingestion.
     */
    public function getCmaSnapshot(int $propertyId): array
    {
        $empty = [
            'has_data' => false,
            'erf_number' => null,
            'gps' => null,
            'extent_m2' => null,
            'municipal_value' => null,
            'municipal_valuation_year' => null,
            'cma_lower' => null,
            'cma_middle' => null,
            'cma_upper' => null,
            'last_sale_date' => null,
            'last_sale_price' => null,
            'indexed_value' => null,
            'cagr_pct' => null,
            'source_presentation_id' => null,
            'source_presentation_title' => null,
            'extracted_at' => null,
            'extracted_by_name' => null,
        ];

        // Primary linkage: presentations.listing_id = property.id
        // Secondary linkage: property_presentation_snapshots pivot (presentation_id may be set there
        // when a snapshot was generated against a presentation that did not set listing_id directly).
        $directIds = DB::table('presentations')
            ->where('listing_id', $propertyId)
            ->whereNull('deleted_at')
            ->pluck('id');

        $pivotIds = DB::table('property_presentation_snapshots')
            ->where('property_id', $propertyId)
            ->whereNotNull('presentation_id')
            ->pluck('presentation_id');

        $allIds = $directIds->merge($pivotIds)->unique();
        if ($allIds->isEmpty()) return $empty;

        $latest = DB::table('presentations')
            ->whereIn('id', $allIds)
            ->whereNull('deleted_at')
            ->orderByDesc('updated_at')
            ->select('id', 'title', 'updated_at', 'created_by_user_id')
            ->first();

        if (!$latest) return $empty;

        $rows = DB::table('presentation_fields')
            ->where('presentation_id', $latest->id)
            ->select('field_key', 'final_value', 'extracted_value', 'override_value')
            ->get();

        if ($rows->isEmpty()) {
            return array_merge($empty, [
                'source_presentation_id' => $latest->id,
                'source_presentation_title' => $latest->title,
                'extracted_at' => $latest->updated_at,
            ]);
        }

        $byKey = [];
        foreach ($rows as $r) {
            $byKey[$r->field_key] = $r->final_value ?? $r->override_value ?? $r->extracted_value;
        }

        $creatorName = null;
        if ($latest->created_by_user_id) {
            $creatorName = DB::table('users')->where('id', $latest->created_by_user_id)->value('name');
        }

        return [
            'has_data' => true,
            'erf_number' => $byKey['subject.erf'] ?? null,
            'gps' => $byKey['subject.gps'] ?? null,
            'extent_m2' => $byKey['subject.extent_m2'] ?? null,
            'municipal_value' => $byKey['municipal.total_value'] ?? null,
            'municipal_valuation_year' => $byKey['municipal.valuation_year'] ?? null,
            'cma_lower' => $byKey['cma.lower_range'] ?? null,
            'cma_middle' => $byKey['cma.middle_range'] ?? null,
            'cma_upper' => $byKey['cma.upper_range'] ?? null,
            'last_sale_date' => $byKey['subject.purchase_date'] ?? null,
            'last_sale_price' => $byKey['subject.purchase_price'] ?? null,
            'indexed_value' => $byKey['subject.indexed_value'] ?? null,
            'cagr_pct' => $byKey['subject.cagr'] ?? null,
            'source_presentation_id' => $latest->id,
            'source_presentation_title' => $latest->title,
            'extracted_at' => $latest->updated_at,
            'extracted_by_name' => $creatorName,
        ];
    }

    /**
     * Prospecting listings (P24 / PP) that have been matched to this property.
     *
     * Surfaces the cross-portal data already accumulated by the prospecting module
     * back into the Property pillar via prospecting_listings.matched_property_id.
     */
    public function getMatchedProspectingListings(int $propertyId, int $limit = 25): Collection
    {
        return collect(DB::table('prospecting_listings')
            ->where('matched_property_id', $propertyId)
            ->whereNull('deleted_at')
            ->orderByDesc('first_seen_at')
            ->limit($limit)
            ->get([
                'id',
                'portal_source',
                'portal_ref',
                'portal_url',
                'address',
                'suburb',
                'price',
                'bedrooms',
                'bathrooms',
                'property_type',
                'agent_name',
                'agency_name',
                'first_seen_at',
                'last_seen_at',
                'price_changed_at',
                'is_active',
                'property_group_id',
            ]));
    }

    /**
     * Unified chronological timeline merging events from every source that
     * references this property: presentations, prospecting, contact-linkage,
     * buyer-match notifications.
     *
     * The single screen-fragment that demonstrates the strategic point: CoreX
     * already knows N things about this property; they just weren't on one screen.
     */
    public function getCrossSourceTimeline(int $propertyId, int $limit = 50): Collection
    {
        $events = collect();

        // Presentations
        $presentations = DB::table('presentations')
            ->where('listing_id', $propertyId)
            ->whereNull('deleted_at')
            ->select('id', 'title', 'status', 'created_at', 'created_by_user_id')
            ->orderByDesc('created_at')
            ->limit(30)
            ->get();
        foreach ($presentations as $p) {
            $events->push((object) [
                'type' => 'presentation',
                'icon' => 'doc',
                'label' => 'CMA presentation',
                'description' => trim(($p->title ?: 'Untitled presentation') . ' · ' . ucfirst((string) $p->status)),
                'date' => $p->created_at,
                'url' => null,
            ]);
        }

        // Prospecting listings discovered
        $prospects = DB::table('prospecting_listings')
            ->where('matched_property_id', $propertyId)
            ->whereNull('deleted_at')
            ->select('id', 'portal_source', 'portal_ref', 'price', 'first_seen_at')
            ->orderByDesc('first_seen_at')
            ->limit(30)
            ->get();
        foreach ($prospects as $pl) {
            $priceLabel = $pl->price ? 'R ' . number_format((float) $pl->price, 0, '.', ',') : 'no price';
            $events->push((object) [
                'type' => 'prospecting',
                'icon' => 'globe',
                'label' => strtoupper((string) $pl->portal_source) . ' listing discovered',
                'description' => "Ref {$pl->portal_ref} · {$priceLabel}",
                'date' => $pl->first_seen_at,
                'url' => null,
            ]);
        }

        // Portal price changes
        $priceChanges = DB::table('prospecting_price_history')
            ->join('prospecting_listings', 'prospecting_listings.id', '=', 'prospecting_price_history.prospecting_listing_id')
            ->where('prospecting_listings.matched_property_id', $propertyId)
            ->whereNull('prospecting_listings.deleted_at')
            ->select(
                'prospecting_price_history.changed_at',
                'prospecting_price_history.old_price',
                'prospecting_price_history.new_price',
                'prospecting_listings.portal_source',
                'prospecting_listings.portal_ref',
            )
            ->orderByDesc('prospecting_price_history.changed_at')
            ->limit(30)
            ->get();
        foreach ($priceChanges as $pc) {
            $oldP = $pc->old_price ? 'R ' . number_format((float) $pc->old_price, 0, '.', ',') : '—';
            $newP = $pc->new_price ? 'R ' . number_format((float) $pc->new_price, 0, '.', ',') : '—';
            $events->push((object) [
                'type' => 'price_change',
                'icon' => 'trend',
                'label' => strtoupper((string) $pc->portal_source) . ' price change',
                'description' => "Ref {$pc->portal_ref} · {$oldP} → {$newP}",
                'date' => $pc->changed_at,
                'url' => null,
            ]);
        }

        // Contact linkage events
        $contacts = DB::table('contact_property')
            ->join('contacts', 'contacts.id', '=', 'contact_property.contact_id')
            ->where('contact_property.property_id', $propertyId)
            ->whereNull('contacts.deleted_at')
            ->select(
                'contacts.id as contact_id',
                'contacts.first_name',
                'contacts.last_name',
                'contact_property.role',
                'contact_property.created_at'
            )
            ->orderByDesc('contact_property.created_at')
            ->limit(20)
            ->get();
        foreach ($contacts as $c) {
            $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));
            $events->push((object) [
                'type' => 'contact',
                'icon' => 'user',
                'label' => 'Contact linked · ' . ucfirst($c->role ?: 'related'),
                'description' => $name ?: 'Unnamed contact',
                'date' => $c->created_at,
                'url' => null,
            ]);
        }

        // Buyer match notifications — cmn has no agency_id, scope via contact_matches.agency_id
        $property = Property::withoutGlobalScopes()->find($propertyId);
        $notifications = DB::table('contact_match_notifications')
            ->join('contact_matches', 'contact_matches.id', '=', 'contact_match_notifications.contact_match_id')
            ->join('contacts', 'contacts.id', '=', 'contact_matches.contact_id')
            ->where('contact_match_notifications.property_id', $propertyId)
            ->when($property && $property->agency_id, fn($q) => $q->where('contact_matches.agency_id', $property->agency_id))
            ->whereNull('contacts.deleted_at')
            ->select(
                'contacts.first_name',
                'contacts.last_name',
                'contact_match_notifications.score',
                'contact_match_notifications.created_at',
                'contact_matches.status as wishlist_status'
            )
            ->orderByDesc('contact_match_notifications.created_at')
            ->limit(30)
            ->get();
        foreach ($notifications as $n) {
            $name = trim(($n->first_name ?? '') . ' ' . ($n->last_name ?? ''));
            $events->push((object) [
                'type' => 'buyer_match',
                'icon' => 'target',
                'label' => "Buyer notified · score {$n->score}%",
                'description' => ($name ?: 'Unknown buyer') . ($n->wishlist_status ? " (wishlist: {$n->wishlist_status})" : ''),
                'date' => $n->created_at,
                'url' => null,
            ]);
        }

        return $events
            ->filter(fn($e) => !empty($e->date))
            ->sortByDesc(fn($e) => $e->date)
            ->take($limit)
            ->values();
    }

    /**
     * Compliance status for a property (mandate, FICA, etc.).
     */
    public function getComplianceStatus(int $propertyId): array
    {
        $property = Property::withoutGlobalScopes()->with('contacts')->find($propertyId);
        if (!$property) return [];

        // Check mandate expiry from calendar events
        $mandateEvent = CalendarEvent::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('category', 'mandate_expiry')
            ->whereNull('deleted_at')
            ->orderByDesc('event_date')
            ->first(['event_date', 'status']);

        // Check seller FICA status
        $sellers = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get();
        $ficaComplete = $sellers->every(fn($c) => $c->ficaStatus() === 'complete');

        return [
            'mandate_type' => $property->mandate_type,
            'mandate_expiry' => $mandateEvent?->event_date?->toDateString(),
            'mandate_expired' => $mandateEvent && $mandateEvent->event_date->isPast(),
            'seller_fica_complete' => $ficaComplete,
            'seller_count' => $sellers->count(),
            'published' => (bool) $property->published_at,
            'days_on_market' => ($dom = $property->listed_date ?? $property->p24_activated_at ?? $property->pp_activated_at ?? $property->published_at ?? $property->created_at)
                ? \App\Support\HumanDiff::daysBetween($dom) : null,
        ];
    }
}
