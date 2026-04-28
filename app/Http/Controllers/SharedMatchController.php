<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\ContactMatch;
use App\Models\ContactMatchFeedback;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Matching\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharedMatchController extends Controller
{
    public function __construct(protected MatchingService $matching) {}

    public function show(Request $request, string $token)
    {
        // Public page — no auth — bypass agency scope so the token resolves
        $match = $this->resolveMatch($token, ['contact', 'createdBy']);

        $contact = $match->contact;

        $overrides = array_filter([
            'category'      => $request->input('category'),
            'property_type' => $request->input('property_type'),
            'price_min'     => $request->filled('price_min') ? (int) $request->input('price_min') : null,
            'price_max'     => $request->filled('price_max') ? (int) $request->input('price_max') : null,
            'beds_min'      => $request->filled('beds_min')  ? (int) $request->input('beds_min')  : null,
            'baths_min'     => $request->filled('baths_min') ? (int) $request->input('baths_min') : null,
            'garages_min'   => $request->filled('garages_min') ? (int) $request->input('garages_min') : null,
            'floor_size_min' => $request->filled('floor_size_min') ? (int) $request->input('floor_size_min') : null,
            'floor_size_max' => $request->filled('floor_size_max') ? (int) $request->input('floor_size_max') : null,
            'erf_size_min'  => $request->filled('erf_size_min') ? (int) $request->input('erf_size_min') : null,
            'erf_size_max'  => $request->filled('erf_size_max') ? (int) $request->input('erf_size_max') : null,
            'suburbs'       => $request->input('suburbs'),
        ], fn ($v) => $v !== null && $v !== '' && $v !== []);

        Property::withoutEvents(fn () => null); // no-op, keep observers on

        $properties = $this->matching->propertiesForMatch($match, $overrides);

        // Existing feedback per property, keyed by property_id
        $feedback = $match->feedback()->get()->keyBy('property_id');

        $filters = [
            'category'     => $overrides['category']      ?? $match->category,
            'propertyType' => $overrides['property_type'] ?? $match->property_type,
            'suburb'       => $match->suburb,
            'priceMin'     => $overrides['price_min']     ?? $match->price_min,
            'priceMax'     => $overrides['price_max']     ?? $match->price_max,
            'bedsMin'      => $overrides['beds_min']      ?? $match->beds_min,
            'bathsMin'     => $overrides['baths_min']     ?? $match->baths_min,
            'garagesMin'   => $overrides['garages_min']   ?? $match->garages_min,
            'floorMin'     => $overrides['floor_size_min'] ?? $match->floor_size_min,
            'floorMax'     => $overrides['floor_size_max'] ?? $match->floor_size_max,
            'erfMin'       => $overrides['erf_size_min']  ?? $match->erf_size_min,
            'erfMax'       => $overrides['erf_size_max']  ?? $match->erf_size_max,
        ];

        $agency = $match->agency_id
            ? Agency::withoutGlobalScope(AgencyScope::class)->find($match->agency_id)
            : null;

        return view('shared.match', compact('match', 'contact', 'properties', 'filters', 'token', 'feedback', 'agency'));
    }

    public function recordView(string $token, int $property): JsonResponse
    {
        $match = $this->resolveMatch($token);

        $match->incrementPropertyView($property);

        return response()->json([
            'ok'    => true,
            'count' => $match->propertyViewCount($property),
        ]);
    }

    public function feedback(Request $request, string $token, int $property): JsonResponse
    {
        $data = $request->validate([
            'reaction' => 'required|in:interested,not_interested,saved',
            'note'     => 'nullable|string|max:500',
        ]);

        $match = $this->resolveMatch($token);

        ContactMatchFeedback::updateOrCreate(
            ['contact_match_id' => $match->id, 'property_id' => $property],
            ['reaction' => $data['reaction'], 'note' => $data['note'] ?? null],
        );

        $match->update(['last_engaged_at' => now()]);

        return response()->json(['ok' => true, 'reaction' => $data['reaction']]);
    }

    /**
     * Look up a match by share_slug (preferred) or share_token (legacy).
     * Public route — bypasses agency scope.
     */
    protected function resolveMatch(string $key, array $with = []): ContactMatch
    {
        return ContactMatch::withoutGlobalScope(AgencyScope::class)
            ->with($with)
            ->where(function ($q) use ($key) {
                $q->where('share_slug', $key)->orWhere('share_token', $key);
            })
            ->firstOrFail();
    }
}
