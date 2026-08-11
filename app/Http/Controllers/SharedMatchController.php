<?php

namespace App\Http\Controllers;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\ContactMatchFeedback;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Services\Matching\MatchingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

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

        // AT-266 — a contact with more than one saved wishlist used to hand out a
        // separate share link per wishlist. Now the link stays singular per
        // contact: whichever wishlist's token was shared, the page resolves
        // EVERY active wishlist for that contact (same agency) and renders each
        // as its own section. `match`/`token` above stay the page's identity
        // (header, agent card, footer); `matchGroups` drives the results.
        $matchGroups = $this->buildMatchGroups($match, $contact, $overrides, $request);

        $agency = $match->agency_id
            ? Agency::withoutGlobalScope(AgencyScope::class)->find($match->agency_id)
            : null;

        return view('shared.match', compact('match', 'contact', 'matchGroups', 'token', 'agency'));
    }

    /**
     * One row per wishlist this contact has, in display order — the token-linked
     * wishlist always leads (so its section opens by default), followed by the
     * contact's other ACTIVE wishlists (a paused/fulfilled/expired sibling stays
     * hidden; the token-linked one shows regardless of its own status, matching
     * the page's pre-existing behaviour of trusting whichever token was shared).
     *
     * The `overrides` query-string filters (the "Change your search criteria"
     * form) apply to exactly ONE wishlist per request: the one named by
     * `match_id` in the request, or — for old bookmarked/shared links that
     * predate `match_id` — the token-linked wishlist. Every other wishlist
     * renders against its own saved criteria, untouched.
     */
    protected function buildMatchGroups(ContactMatch $match, Contact $contact, array $overrides, Request $request): Collection
    {
        $requestedMatchId = $request->filled('match_id') ? (int) $request->input('match_id') : null;

        $siblings = ContactMatch::withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)
            ->where('agency_id', $match->agency_id)
            ->where(function ($q) use ($match) {
                $q->where('status', ContactMatch::STATUS_ACTIVE)->orWhere('id', $match->id);
            })
            ->with('createdBy')
            ->orderByDesc('is_primary')
            ->orderBy('created_at')
            ->get();

        $ordered = collect([$siblings->firstWhere('id', $match->id) ?? $match])
            ->merge($siblings->reject(fn (ContactMatch $m) => $m->id === $match->id));

        return $ordered->map(function (ContactMatch $m) use ($match, $overrides, $requestedMatchId) {
            $appliesOverride = $requestedMatchId !== null
                ? $requestedMatchId === $m->id
                : $m->id === $match->id;

            // Respect the agency-level "matches_visibility_scope" setting on the
            // shared (client-facing) page — agent / branch / agency stock.
            $matchOverrides = $appliesOverride ? $overrides : [];
            $matchOverrides += MatchingService::scopeOverridesFor($m);

            $properties = $this->matching->propertiesForMatch($m, $matchOverrides);

            return [
                'match'      => $m,
                'properties' => $properties,
                'feedback'   => $m->feedback()->get()->keyBy('property_id'),
                'filters'    => [
                    'category'     => $matchOverrides['category']      ?? $m->category,
                    'propertyType' => $matchOverrides['property_type'] ?? $m->property_type,
                    'suburb'       => $m->suburb,
                    'priceMin'     => $matchOverrides['price_min']      ?? $m->price_min,
                    'priceMax'     => $matchOverrides['price_max']      ?? $m->price_max,
                    'bedsMin'      => $matchOverrides['beds_min']       ?? $m->beds_min,
                    'bathsMin'     => $matchOverrides['baths_min']      ?? $m->baths_min,
                    'garagesMin'   => $matchOverrides['garages_min']    ?? $m->garages_min,
                    'floorMin'     => $matchOverrides['floor_size_min'] ?? $m->floor_size_min,
                    'floorMax'     => $matchOverrides['floor_size_max'] ?? $m->floor_size_max,
                    'erfMin'       => $matchOverrides['erf_size_min']   ?? $m->erf_size_min,
                    'erfMax'       => $matchOverrides['erf_size_max']   ?? $m->erf_size_max,
                ],
                // The section that opens by default follows whichever wishlist's
                // filter form was just submitted (match_id), not just the URL
                // token — otherwise editing a sibling's criteria and reloading
                // re-collapses the very section the client just filtered.
                'isCurrent'  => $appliesOverride,
                // Each wishlist keeps its OWN token for record-view/feedback calls
                // so a reaction on a sibling's property never gets misfiled against
                // the wishlist the page happened to be opened with.
                'token'      => $m->share_slug ?: $m->share_token,
            ];
        })->values();
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

        // Public shared-match link — no Auth::user(), so stamp agency_id from
        // the match (Contact pillar); ContactMatchFeedback.agency_id is NOT NULL
        // and BelongsToAgency cannot infer it here. In values so it is set on the
        // create leg of updateOrCreate.
        ContactMatchFeedback::updateOrCreate(
            ['contact_match_id' => $match->id, 'property_id' => $property],
            [
                'agency_id' => $match->agency_id,
                'reaction'  => $data['reaction'],
                'note'      => $data['note'] ?? null,
            ],
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
