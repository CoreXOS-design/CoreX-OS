<?php

namespace App\Http\Controllers;

use App\Models\ContactMatch;
use App\Models\Property;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedMatchController extends Controller
{
    public function show(Request $request, string $token)
    {
        $match = ContactMatch::where('share_token', $token)
            ->with(['contact', 'createdBy'])
            ->firstOrFail();

        $contact = $match->contact;

        // Build property query from saved match criteria,
        // optionally overridden by client-submitted filter params
        $query = Property::with(['agent', 'branch', 'agency'])
            ->whereNotIn('status', ['sold', 'withdrawn']);

        $category     = $request->input('category',      $match->category);
        $propertyType = $request->input('property_type', $match->property_type);
        $suburb       = $request->input('suburb',        $match->suburb);
        $priceMin     = (int) $request->input('price_min', $match->price_min ?? 0) ?: null;
        $priceMax     = (int) $request->input('price_max', $match->price_max ?? 0) ?: null;
        $bedsMin      = (int) $request->input('beds_min',  $match->beds_min  ?? 0) ?: null;
        $bathsMin     = (int) $request->input('baths_min', $match->baths_min ?? 0) ?: null;
        $garagesMin   = (int) $request->input('garages_min', $match->garages_min ?? 0) ?: null;
        $floorMin     = (int) $request->input('floor_size_min', $match->floor_size_min ?? 0) ?: null;
        $floorMax     = (int) $request->input('floor_size_max', $match->floor_size_max ?? 0) ?: null;
        $erfMin       = (int) $request->input('erf_size_min', $match->erf_size_min ?? 0) ?: null;
        $erfMax       = (int) $request->input('erf_size_max', $match->erf_size_max ?? 0) ?: null;

        if ($category)     $query->where('category', $category);
        if ($propertyType) $query->where('property_type', $propertyType);
        if ($suburb)       $query->where('suburb', 'like', '%' . $suburb . '%');
        if ($priceMin)     $query->where('price', '>=', $priceMin);
        if ($priceMax)     $query->where('price', '<=', $priceMax);
        if ($bedsMin)      $query->where('beds', '>=', $bedsMin);
        if ($bathsMin)     $query->where('baths', '>=', $bathsMin);
        if ($garagesMin)   $query->where('garages', '>=', $garagesMin);
        if ($floorMin)     $query->where('size_m2', '>=', $floorMin);
        if ($floorMax)     $query->where('size_m2', '<=', $floorMax);
        if ($erfMin)       $query->where('erf_size_m2', '>=', $erfMin);
        if ($erfMax)       $query->where('erf_size_m2', '<=', $erfMax);

        if (!empty($match->hidden_property_ids)) {
            $query->whereNotIn('id', $match->hidden_property_ids);
        }

        $properties = $query->orderByDesc('created_at')->paginate(5)->withQueryString();

        $filters = compact(
            'category', 'propertyType', 'suburb',
            'priceMin', 'priceMax',
            'bedsMin', 'bathsMin', 'garagesMin',
            'floorMin', 'floorMax', 'erfMin', 'erfMax'
        );

        return view('shared.match', compact('match', 'contact', 'properties', 'filters', 'token'));
    }

    public function recordView(string $token, int $property): JsonResponse
    {
        $match = ContactMatch::where('share_token', $token)->firstOrFail();
        $match->incrementPropertyView($property);

        return response()->json(['ok' => true, 'count' => $match->propertyViewCount($property)]);
    }
}
