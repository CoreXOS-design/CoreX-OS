<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Property;
use App\Services\PublicLinks\PublicLinkUnavailableResponder;
use Illuminate\Http\Request;

class PublicAgencyPropertiesController extends Controller
{
    public function index(Request $request, string $agencySlug)
    {
        $agency = Agency::where('slug', $agencySlug)->firstOrFail();

        $q = Property::where('agency_id', $agency->id)
            ->whereNull('deleted_at')
            ->whereIn('status', ['Active', 'NewListing', 'Reduced', 'active', 'new_listing', 'reduced']);

        if ($type = $request->query('type')) {
            $q->where('listing_type', $type);
        }
        if ($search = $request->query('search')) {
            $q->where(function ($w) use ($search) {
                $w->where('title', 'like', "%{$search}%")
                  ->orWhere('headline', 'like', "%{$search}%")
                  ->orWhere('suburb', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhere('street_name', 'like', "%{$search}%");
            });
        }

        $properties = $q->orderByDesc('id')->paginate(24)->withQueryString();

        return view('public.agency-properties.index', compact('agency', 'properties'));
    }

    public function show(string $agencySlug, string $property)
    {
        $agency = Agency::where('slug', $agencySlug)->firstOrFail();

        // 2026-08-24 (Johan) — public-link resilience: 167 non-marketable
        // properties agency-wide plain-404'd here with no agency context,
        // despite the URL already carrying the agency slug (audit item #4,
        // .ai/audits/2026-08-24-public-link-resilience-audit.md). Same shape
        // of bug as the seller-live-link and property-preview fixes done
        // earlier today — a sold/withdrawn/deleted property looks the same
        // as a link that never existed. Fetch unscoped so we control the
        // not-found/wrong-agency/deleted case ourselves instead of letting
        // implicit route-model-binding throw before this method runs.
        $propertyModel = Property::withoutGlobalScopes()->withTrashed()->with('agent')->find($property);

        if (!$propertyModel || $propertyModel->deleted_at !== null || (int) $propertyModel->agency_id !== (int) $agency->id) {
            // Unknown/wrong-agency property id — nothing to derive an agent
            // from, agency-only branding (matches the "what we actually
            // know" rule: never promise a personal contact the data can't
            // support).
            return $this->showUnavailable($agency);
        }

        // Public listing — must be compliance-ready
        $svc = app(\App\Services\Compliance\MarketingReadinessService::class);
        if (!$svc->isMarketable($propertyModel)) {
            // A real (if now dead) property IS resolvable here — offer its
            // own listing agent, same as the seller-live-link page does for
            // the same "sold/withdrawn" reason.
            return $this->showUnavailable($agency, $propertyModel->agent);
        }

        $propertyModel->load('agent');

        return view('public.agency-properties.show', ['agency' => $agency, 'property' => $propertyModel]);
    }

    /**
     * 2026-08-25 (Johan) — delegates to the shared PublicLinkUnavailableResponder
     * (same one SellerLinkController uses) rather than its own
     * public.agency-properties.unavailable view — one shared page, not two
     * near-identical ones. Status moved 404 → 410: the agency and its slug
     * are genuinely real here (that's how we got an agency to brand with at
     * all) — "gone" fits better than "not found" for a property that used to
     * be listed and now isn't, matching the convention used everywhere else
     * fixed today. Route-model-binding on the caller's side is kept exactly
     * as it was elsewhere (this is production-facing code, no rewrite of
     * what's already proven); the only change is where a dead-end used to
     * plain-404, it now routes here instead. Never distinguishes wrong-
     * agency / soft-deleted / not-marketable on the page itself — the
     * standing rule is a dead link must never reveal why it died, so all
     * three land on the same message.
     */
    private function showUnavailable(Agency $agency, $agent = null)
    {
        return app(PublicLinkUnavailableResponder::class)->respond(
            $agency->id,
            "Shucks — this property isn't available any more",
            'It may have sold, been withdrawn, or come off the market — but there\'s new stock every week.',
            $agent,
            primaryAction: ['label' => 'See current stock', 'url' => route('public.agency.properties.index', ['agencySlug' => $agency->slug])],
            eyebrow: 'This listing',
        );
    }
}
