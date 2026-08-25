<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Services\PropertyIntelligenceService;
use App\Services\PublicLinks\PublicLinkUnavailableResponder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SellerLinkController extends Controller
{
    /**
     * Public endpoint: render seller live page for a valid token.
     */
    public function show(string $token)
    {
        $link = PropertySellerLink::where('token', $token)->first();

        // 2026-08-24 (Johan) — an UNKNOWN token (never existed) has nothing to
        // resolve an agency from, so it stays a plain generic 404 per the
        // 3-branch policy (routes through errors.404-guest, built earlier
        // today). A REVOKED token is different: the link WAS real, so it's a
        // valid-but-dead case and gets the same agency-branded, agent-contact,
        // route-back treatment as 'deleted'/'sold' below — not the old bare
        // seller-link.revoked view (hardcoded dark background, no branding,
        // no agent, no way back), which was left untouched by that earlier
        // fix and reported back as a real dead end a seller actually landed
        // on. Fixed to the same standard as everything else, not a special case.
        if (!$link) {
            abort(404);
        }
        if ($link->revoked_at) {
            return $this->showUnavailable($link, $link->property, 'revoked');
        }

        $property = $link->property;

        // 2026-08-24 (Johan) — a soft-deleted property used to crash here:
        // getFeedbackRollup(int $propertyId, ...) is strictly typed and
        // $property->id on a null $property throws, uncaught, 500. And a
        // CONCLUDED property (sold/transferred/rented/let_out — the single
        // source of truth is Property::isConcluded(), never a raw status
        // string compare) used to render as if still live: no error, but a
        // wrong page — a seller or buyer holding the link sees a listing
        // that looks current for a property that is gone. Worse than a
        // crash, because nothing tells them. Both now get the same
        // courteous treatment SharedMatchController::showExpired() and
        // PublicPresentationController::renderUnavailable() already use —
        // copy that pattern, not a third dialect of it.
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
        $marketPosition = $intel->getLatestMarketPosition($property->id);
        $comparables = $intel->getComparableListings($property->id);
        $portalPerformance = $intel->getPortalPerformance($property->id, rangeDays: 30);
        // 2026-08-25 (Johan) — "port the graph": SAME data source the
        // internal Intelligence tab's Portal Engagement chart uses
        // (corex/properties/intelligence/_portal-engagement-chart.blade.php),
        // no second query. 180 days fetched once; the page's own 30D/90D/6M
        // toggle slices it client-side, same convention as that partial's
        // Alpine store (not reused here — this is a standalone public page
        // with no Alpine/Vite app shell, so the toggle is plain JS instead;
        // see the view for why).
        $portalEngagement = $intel->getPortalEngagementSeries($property->id, 180);
        $recommendations = DB::table('property_recommendations')
            ->where('property_id', $property->id)
            ->where('seller_visible', true)
            ->whereNull('dismissed_at')
            ->whereNull('actioned_at')
            ->whereNotNull('seller_facing_title')
            ->orderByDesc('generated_at')
            ->get();

        return view('seller-link.live', [
            'property' => $property,
            'seller' => $contact,
            'agency' => $agency,
            'feedbackRollup' => $feedbackRollup,
            'viewingFeedback' => $this->buildSellerSafeFeedback($property->id),
            'buyerDemand' => $this->buildBuyerDemand($property->id, $intel),
            'priceHistory' => $this->buildPriceHistory($property->id),
            'portalPerformance' => $portalPerformance,
            'portalEngagement' => $portalEngagement,
            'compliance' => $compliance,
            'marketPosition' => $marketPosition,
            'comparables' => $comparables,
            'recommendations' => $recommendations,
            'link' => $link,
        ]);
    }

    /**
     * 2026-08-24 (Johan) — seller live page rebuild. Buyer demand, seller-
     * facing, per .ai/audits/2026-08-24-seller-live-link-data-availability.md
     * Part 2: MatchingService::matchesForProperty is the SAME canonical
     * engine the internal Core Matches tab and cc4's suburb report use — real
     * demand, not a fabricated count. PropertyIntelligenceService::
     * getBuyerInterestSignals() already wraps it, but returns real buyer
     * names/ids for internal (agent-authenticated) use — never safe to hand
     * to a public, forwardable-token page. Collapsed here into counts by
     * tier only; no name, no id, no contact path ever leaves this method.
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
     * 2026-08-24 (Johan) — seller-safe viewing feedback. Same privacy
     * boundary as buildBuyerDemand(): PropertyIntelligenceService::
     * getRecentViewings() returns buyer names (built for the internal
     * property page) — this strips every identifying field before it ever
     * reaches the view, keeping only what the seller is entitled to: that a
     * viewing happened, what the outcome was, and the seller-visible note
     * (never internal_notes — that split already exists at the data layer,
     * used here as the gate, not re-derived).
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
     * 2026-08-24 (Johan) — price-change history, single-line-per-event, from
     * property_audit_log (event_type='price_changed') — no dedicated price-
     * history table exists (properties.price is a single current value).
     * Low fill rate (11.7% of active properties, per the availability audit)
     * — folded in as a small strip that's simply absent when there's nothing
     * to show, per Johan's instruction not to build a section that renders
     * empty for most sellers. human_summary is already agency/seller-safe
     * (just a price figure and a date, no PII).
     */
    private function buildPriceHistory(int $propertyId): \Illuminate\Support\Collection
    {
        return DB::table('property_audit_log')
            ->where('property_id', $propertyId)
            ->where('event_type', 'price_changed')
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['human_summary', 'created_at']);
    }

    /**
     * VALID token, resource dead — either the property was soft-deleted
     * ($property is null) or it's CONCLUDED (sold/transferred/rented/let_out).
     * Same shape as SharedMatchController::showExpired(): resolve the agency
     * from whatever's still resolvable, show the seller's current agent only
     * if they're actually still live (is_active + not deleted), otherwise
     * fall through to the agency's own contact details via the SAME shared
     * fallback service every other public link in this codebase already
     * uses — not a new resolver.
     *
     * A sold property is, commercially, a live buyer lead standing on the
     * page — not just an error case — so the 'sold' reason gets its own
     * "similar properties" call to action the 'deleted'/'revoked' reasons
     * don't (there's no agency-marketable property left to point at, or no
     * property context in the revoked case).
     *
     * 2026-08-25 (Johan) — delegates to the shared
     * PublicLinkUnavailableResponder rather than rendering its own
     * seller-link/unavailable.blade.php — this file's own reason-branching
     * logic used to be near-identical to PublicAgencyPropertiesController's;
     * both now feed the ONE shared view. See that service's docblock.
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
