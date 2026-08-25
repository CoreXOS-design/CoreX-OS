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

    public function show(string $agencySlug, Property $property)
    {
        $agency = Agency::where('slug', $agencySlug)->firstOrFail();
        if ($property->agency_id !== $agency->id) {
            return $this->showUnavailable($agency);
        }

        // Public listing — must be compliance-ready
        $svc = app(\App\Services\Compliance\MarketingReadinessService::class);
        if (!$svc->isMarketable($property)) {
            return $this->showUnavailable($agency, $property->agent);
        }

        $property->load('agent');

        return view('public.agency-properties.show', compact('agency', 'property'));
    }

    /**
     * 2026-08-25 (Johan) — route-model-binding kept exactly as it was
     * (this is production-facing code, no rewrite of what's already
     * proven); the only change is where a dead-end used to plain-404, it
     * now routes to the shared "no longer available" page instead. Never
     * distinguishes wrong-agency / soft-deleted / not-marketable on the
     * page itself — the standing rule is a dead link must never reveal
     * why it died, so all three land on the same message.
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
