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

    public function show(string $agencySlug, Property $property)
    {
        $agency = Agency::where('slug', $agencySlug)->firstOrFail();
        abort_unless($property->agency_id === $agency->id, 404);

        $property->load('agent');

        return view('public.agency-properties.show', compact('agency', 'property'));
    }
}
