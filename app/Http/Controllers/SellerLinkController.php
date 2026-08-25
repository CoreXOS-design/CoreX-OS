<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Services\PropertyIntelligenceService;
use App\Services\PublicLinks\PublicLinkUnavailableResponder;
use App\Support\HumanDiff;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerLinkController extends Controller
{
    /**
     * 2026-08-25 (Johan) — CORRECTION: CoreX had been calling accepted
     * offers "achieved sales" on a page a seller reads to price their own
     * home. Every customer-facing word for this distinction lives HERE and
     * ONLY here — a rename is a one-line change to this array, never a
     * hunt through the view. "Sold" may ONLY ever be used for the
     * registered-sale branch (getRegisteredSaleComparables(), the legacy
     * `deals` table, registration_date IS NOT NULL). The under-offer
     * branch (getUnderOfferComparables(), deals_v2, NOT yet registered)
     * must never say "sold" or "achieved" anywhere.
     */
    private const LABELS = [
        'sold_heading'          => 'What has actually sold near you',
        'sold_subtitle'         => 'Registered sales in your suburb, last 12 months.',
        'sold_verb'             => 'sold',
        'under_offer_heading'   => 'What has recently gone under offer near you',
        'under_offer_subtitle'  => 'Accepted offers in your suburb, last 6 months — not yet registered, so not final.',
        'under_offer_verb'      => 'went under offer',
    ];

    /**
     * Public endpoint: render seller live page for a valid token.
     *
     * 2026-08-25 (Johan) — expanded per spec .ai/specs/seller-live-link.md:
     * "a page that should prove to a seller that we are working, and the
     * data we provide them should show this." Every section answers "is my
     * agent actually doing anything" or collapses rather than guess.
     */
    public function show(string $token)
    {
        $link = PropertySellerLink::where('token', $token)->first();

        if (!$link) {
            abort(404);
        }
        if ($link->revoked_at) {
            return $this->showUnavailable($link, $link->property, 'revoked');
        }

        $property = $link->property;

        if (!$property) {
            return $this->showUnavailable($link, null, 'deleted');
        }
        if ($property->isConcluded()) {
            return $this->showUnavailable($link, $property, 'sold');
        }

        // Record access. This is a PUBLIC page — a failure to log the visit must
        // never 500 the seller out of their own report. Stamp agency_id from the
        // link's pillar (property_seller_link_accesses.agency_id is NOT NULL and
        // this is a raw insert, so BelongsToAgency's auto-stamp does not apply).
        try {
            $accessAgencyId = $link->agency_id ?: ($property?->agency_id);
            $link->increment('access_count');
            $link->update(['last_accessed_at' => now()]);
            if ($accessAgencyId) {
                DB::table('property_seller_link_accesses')->insert([
                    'link_id' => $link->id,
                    'agency_id' => $accessAgencyId,
                    'accessed_at' => now(),
                    'ip_address' => request()->ip(),
                    'user_agent' => substr(request()->userAgent() ?? '', 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        $contact = $link->contact;
        $agency = Agency::withoutGlobalScopes()->find($property->agency_id);
        $intel = app(PropertyIntelligenceService::class);

        $feedbackRollup = $intel->getFeedbackRollup($property->id, excludeInternalOnly: true);
        $compliance = $intel->getComplianceStatus($property->id);
        $activeComparables = $intel->getActiveComparables($property->id);
        // 2026-08-25 CORRECTION — was getAchievedComparableSales() reading
        // property_sold_records, whose sold_price mirrors the property's
        // own advertised price (confirmed fake by SuburbReportDataService's
        // own 2026-08-24 finding). Two real, separately-labelled sources
        // now: registered sales (may say "sold") and accepted offers not
        // yet registered (may NEVER say "sold"). See self::LABELS.
        $registeredSales = $intel->getRegisteredSaleComparables($property->id);
        $underOfferSales = $intel->getUnderOfferComparables($property->id);
        $feedbackThemes = $intel->getFeedbackThemes($property->id, excludeInternalOnly: true);
        $portalPerformance = $intel->getPortalPerformance($property->id, rangeDays: 30);
        // Portal Engagement chart — SAME data source the internal Intelligence
        // tab's chart uses (getPortalEngagementSeries()), no second query. 180
        // days fetched once; the page's own 30D/90D/6M toggle slices it
        // client-side in plain JS.
        $portalEngagement = $intel->getPortalEngagementSeries($property->id, 180);
        $recommendations = DB::table('property_recommendations')
            ->where('property_id', $property->id)
            ->where('seller_visible', true)
            ->whereNull('dismissed_at')
            ->whereNull('actioned_at')
            ->whereNotNull('seller_facing_title')
            ->orderByDesc('generated_at')
            ->get();

        $priceChangeEvents = $this->buildPriceChangeEvents($property->id);
        $daysOnMarket = $property->listed_date ? HumanDiff::daysBetween($property->listed_date) : null;
        $priceChangeNarrative = $this->buildPriceChangeNarrative($priceChangeEvents, $portalEngagement['series'] ?? []);
        $registeredComparison = $this->buildBestComparison($property, $daysOnMarket, $registeredSales);
        $underOfferComparison = $this->buildBestComparison($property, $daysOnMarket, $underOfferSales);

        return view('seller-link.live', [
            'property' => $property,
            'seller' => $contact,
            'agency' => $agency,
            'labels' => self::LABELS,
            'feedbackRollup' => $feedbackRollup,
            'feedbackThemes' => $feedbackThemes,
            'viewingFeedback' => $this->buildSellerSafeFeedback($property->id),
            'buyerDemand' => $this->buildBuyerDemand($property->id, $intel),
            'priceChangeEvents' => $priceChangeEvents,
            'priceChangeNarrative' => $priceChangeNarrative,
            'portalPerformance' => $portalPerformance,
            'portalEngagement' => $portalEngagement,
            'compliance' => $compliance,
            'daysOnMarket' => $daysOnMarket,
            'activeComparables' => $activeComparables,
            'registeredSales' => $registeredSales,
            'registeredComparison' => $registeredComparison,
            'underOfferSales' => $underOfferSales,
            'underOfferComparison' => $underOfferComparison,
            'recommendations' => $recommendations,
            'portalsLive' => $this->buildPortalsLive($property),
            'link' => $link,
        ]);
    }

    /**
     * Seller-facing buyer demand. MatchingService::matchesForProperty (via
     * getBuyerInterestSignals()) is the SAME canonical engine the internal
     * Core Matches tab uses — real demand, not a fabricated count. Collapsed
     * here into counts by tier only; no buyer name, id, or contact path ever
     * leaves this method — that data is agent-authenticated-only.
     */
    private function buildBuyerDemand(int $propertyId, PropertyIntelligenceService $intel): array
    {
        $signals = $intel->getBuyerInterestSignals($propertyId);

        return [
            'total'  => $signals->count(),
            'strong' => $signals->where('tier', 'strong')->count(),
            'good'   => $signals->where('tier', 'good')->count(),
            'fair'   => $signals->where('tier', 'fair')->count(),
        ];
    }

    /**
     * Seller-safe viewing feedback. getRecentViewings() returns buyer names
     * (built for the internal property page) — this strips every
     * identifying field before it ever reaches the view, keeping only what
     * the seller is entitled to: that a viewing happened, what the outcome
     * was, and the seller-visible note (never internal_notes).
     */
    private function buildSellerSafeFeedback(int $propertyId): array
    {
        $intel = app(PropertyIntelligenceService::class);
        $viewings = $intel->getRecentViewings($propertyId, limit: 8, excludeInternalOnly: true);

        $items = $viewings->flatMap(function ($v) {
            return collect($v['feedback'])->map(fn ($fb) => [
                'outcome_label' => $fb['outcome_label'],
                'notes'         => $fb['seller_notes'],
                'date'          => $fb['captured_at'],
            ]);
        })
        ->filter(fn ($row) => !empty($row['outcome_label']) || !empty($row['notes']))
        ->sortByDesc('date')
        ->take(5)
        ->values()
        ->all();

        return $items;
    }

    /**
     * 2026-08-25 (Johan) — "Activity over time, WITH PRICE CHANGES MARKED ON
     * IT... did the reduction move anything." Structured (numeric price +
     * date), not the human_summary string buildPriceHistory() returns —
     * this feeds both the chart's markers and the before/after narrative
     * sentence below. Same source table as buildPriceHistory() (no second
     * query family, just structured fields instead of a pre-formatted
     * string) — old_values/new_values are JSON {"price": N}.
     */
    private function buildPriceChangeEvents(int $propertyId): array
    {
        return DB::table('property_audit_log')
            ->where('property_id', $propertyId)
            ->where('event_type', 'price_changed')
            ->orderBy('created_at')
            ->get(['old_values', 'new_values', 'created_at'])
            ->map(function ($row) {
                $old = json_decode($row->old_values ?? '{}', true)['price'] ?? null;
                $new = json_decode($row->new_values ?? '{}', true)['price'] ?? null;
                return [
                    'date' => \Carbon\Carbon::parse($row->created_at)->format('Y-m-d'),
                    'old_price' => $old !== null ? (float) $old : null,
                    'new_price' => $new !== null ? (float) $new : null,
                ];
            })
            ->filter(fn ($e) => $e['old_price'] !== null && $e['new_price'] !== null && $e['old_price'] != $e['new_price'])
            ->values()
            ->all();
    }

    /**
     * "did the reduction move anything" — the honest, re-derivable version:
     * 7-day average daily views in the 7 days strictly before the most
     * recent price change, vs the 7-day average starting on the change date
     * (fewer days if the change is too recent to have 7 yet — never fewer
     * than 1, and never fabricated for a change with zero days elapsed).
     * Deliberately an AVERAGE both sides, not a single "the day after" spot
     * figure — a single day is a defensible-sounding number that is
     * actually a cherry-pick; an average of the same window length on both
     * sides is the same claim any second reader can re-derive identically.
     * Null when there's no price change, or the series doesn't cover it.
     */
    private function buildPriceChangeNarrative(array $priceChangeEvents, array $engagementSeries): ?array
    {
        if (empty($priceChangeEvents)) return null;

        $latest = end($priceChangeEvents);
        $changeDate = \Carbon\Carbon::parse($latest['date']);
        $byDate = collect($engagementSeries)->keyBy('date');

        $before = collect(range(1, 7))
            ->map(fn ($d) => $changeDate->copy()->subDays($d)->format('Y-m-d'))
            ->map(fn ($d) => $byDate[$d]['views'] ?? 0);

        $daysSinceChange = min(7, (int) $changeDate->diffInDays(now()) + 1);
        if ($daysSinceChange < 1) return null;

        $after = collect(range(0, $daysSinceChange - 1))
            ->map(fn ($d) => $changeDate->copy()->addDays($d)->format('Y-m-d'))
            ->map(fn ($d) => $byDate[$d]['views'] ?? 0);

        return [
            'date' => $changeDate,
            'new_price' => $latest['new_price'],
            'direction' => $latest['new_price'] < $latest['old_price'] ? 'reduced' : 'increased',
            'before_avg' => round($before->avg(), 1),
            'after_avg' => round($after->avg(), 1),
            'after_days' => $daysSinceChange,
        ];
    }

    /**
     * "your 2 bed 2 bath ... is on the market 90 days; a comparable ...
     * {verb} in 40 days" — picks the best beds-matching comparable from an
     * ALREADY family-filtered collection (getUnderOfferComparables()/
     * getRegisteredSaleComparables() both family-gate via TitleTypeClassifier
     * before this ever sees them, so both comparable sections — active,
     * under-offer, registered — agree on what counts as "the same kind of
     * property"), and only when the subject itself has a real days-on-market
     * figure to compare. Deliberately verb-free — this returns STRUCTURE
     * only; "sold" vs "went under offer" is templated in the view from
     * self::LABELS, the one place those words live. Null when there's no
     * usable match.
     */
    private function buildBestComparison(Property $property, ?int $daysOnMarket, \Illuminate\Support\Collection $comparables): ?array
    {
        if ($daysOnMarket === null || $comparables->isEmpty()) return null;

        $best = $comparables
            ->sortBy(function ($s) use ($property) {
                // Prefer a beds-match; among those, keep the collection's
                // own order (both source methods already sort most-recent-first).
                return ($property->beds && $s['beds'] === $property->beds) ? 0 : 1;
            })
            ->first();

        if (!$best) return null;

        $days = $best['days_to_offer'] ?? $best['days_to_sell'] ?? null;
        if ($days === null) return null;

        return [
            'subject_days' => $daysOnMarket,
            'comp_beds' => $best['beds'],
            'comp_baths' => $best['baths'],
            'comp_type' => $best['property_type'],
            'comp_days' => $days,
            'comp_price' => $best['price'],
        ];
    }

    /** Live portal labels only (Property24 / Private Property / Company Website), no jargon, no URLs. */
    private function buildPortalsLive(Property $property): array
    {
        return collect($property->portalLinks())
            ->where('status', 'live')
            ->pluck('label')
            ->values()
            ->all();
    }

    /**
     * VALID token, resource dead — either the property was soft-deleted
     * ($property is null) or it's CONCLUDED (sold/transferred/rented/let_out),
     * or the link itself was revoked. Resolve the agency from whatever's
     * still resolvable, show the seller's current agent only if they're
     * actually still live (is_active + not deleted), otherwise fall through
     * to the agency's own contact details via PublicLinkUnavailableResponder.
     */
    private function showUnavailable(PropertySellerLink $link, ?Property $property, string $reason)
    {
        $agencyId = $property?->agency_id ?? $link->agency_id;
        $contact = $link->contact;
        $currentAgent = $contact?->agent;

        $title = match ($reason) {
            'sold'    => 'This property has sold',
            'revoked' => 'This link is no longer active',
            default   => 'This property is no longer available',
        };
        $body = match ($reason) {
            'sold'    => 'The link you followed pointed to a listing that has since sold. It is no longer being marketed.',
            'revoked' => 'This seller live link has been switched off. Your agent can send you an up-to-date one.',
            default   => 'The link you followed pointed to a property listing that no longer exists.',
        };
        $primaryAction = null;
        if ($reason === 'sold' && $agencyId && ($url = $this->agencyPropertiesUrl($agencyId))) {
            $primaryAction = ['label' => 'Looking for something similar? View current listings', 'url' => $url];
        }

        return app(PublicLinkUnavailableResponder::class)->respond(
            $agencyId, $title, $body, $currentAgent, $primaryAction,
        );
    }

    /** Resolves the agency's public listings URL for the 'sold' CTA above, or null if the agency has no slug. */
    private function agencyPropertiesUrl(int $agencyId): ?string
    {
        $slug = Agency::withoutGlobalScopes()->find($agencyId)?->slug;
        return $slug ? route('public.agency.properties.index', ['agencySlug' => $slug]) : null;
    }

    /**
     * Agent: generate a new seller link for a property + contact.
     */
    public function generate(Request $request)
    {
        $request->validate([
            'property_id' => 'required|integer|exists:properties,id',
            'contact_id' => 'required|integer|exists:contacts,id',
        ]);

        // Revoke any existing active link for this property + contact
        PropertySellerLink::where('property_id', $request->property_id)
            ->where('contact_id', $request->contact_id)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);

        $link = PropertySellerLink::create([
            'property_id' => $request->property_id,
            'contact_id' => $request->contact_id,
            'token' => PropertySellerLink::generateToken(),
            'generated_by_user_id' => auth()->id(),
            'generated_at' => now(),
        ]);

        $url = url('/property/live/' . $link->token);

        if ($request->wantsJson()) {
            return response()->json(['url' => $url, 'link_id' => $link->id]);
        }

        return back()->with('success', 'Seller link generated.')->with('seller_link_url', $url);
    }

    /**
     * Agent: revoke a seller link.
     */
    public function revoke(Request $request, PropertySellerLink $link)
    {
        $link->update(['revoked_at' => now(), 'revoked_by_user_id' => auth()->id()]);

        return back()->with('success', 'Seller link revoked.');
    }

    /**
     * Demo endpoint: public, shows sample live page.
     */
    public function demo()
    {
        return view('seller-link.demo');
    }
}
