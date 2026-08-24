<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Property;
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
        $propertyModel = Property::withoutGlobalScopes()->withTrashed()->find($property);

        if (!$propertyModel || $propertyModel->deleted_at !== null || (int) $propertyModel->agency_id !== (int) $agency->id) {
            return $this->showUnavailable($agency);
        }

        // Public listing — must be compliance-ready
        $svc = app(\App\Services\Compliance\MarketingReadinessService::class);
        if (!$svc->isMarketable($propertyModel)) {
            return $this->showUnavailable($agency);
        }

        $propertyModel->load('agent');

        return view('public.agency-properties.show', ['agency' => $agency, 'property' => $propertyModel]);
    }

    private function showUnavailable(Agency $agency)
    {
        $fallbackContact = app(\App\Services\Leads\SharedLinkReengagementService::class)->agencyFallbackContact($agency);

        return response()->view('public.agency-properties.unavailable', [
            'agency'        => $agency,
            'fallbackPhone' => $fallbackContact['phone'],
            'fallbackEmail' => $fallbackContact['email'],
        ], 404);
    }
}
