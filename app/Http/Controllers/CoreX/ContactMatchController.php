<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\Matching\MatchingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ContactMatchController extends Controller
{
    /**
     * Canonical feature token list for the wishlist chip selectors
     * (must_have_features, nice_to_have_features, deal_breakers).
     * Until a settings table owns this, the list lives here. Tokens are
     * lower_snake_case; labels are derived via Str::headline() in the view.
     */
    public const FEATURE_OPTIONS = [
        'pool',
        'furnished',
        'pet_friendly',
        'garden',
        'sea_view',
        'security',
        'garage',
        'fibre',
        'solar',
        'air_conditioning',
        'study',
        'granny_flat',
        'balcony',
        'borehole',
    ];

    public function __construct(protected MatchingService $matching) {}

    public function index()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $allMatches = ContactMatch::with(['contact.type', 'createdBy', 'feedback'])
            ->whereHas('contact')
            ->where('created_by_user_id', $user->id)
            ->orderByRaw("FIELD(status,'active','paused','fulfilled','expired')")
            ->latest()
            ->get();

        $matchCounts = $this->propertyCountsFor($allMatches);

        $matches = $allMatches->groupBy('contact_id');

        $contacts = Contact::whereIn('id', $matches->keys())
            ->with('type')
            ->orderBy('first_name')
            ->get()
            ->map(fn($c) => [
                'contact' => $c,
                'matches' => $matches->get($c->id, collect()),
            ]);

        return view('corex.core-matches.index', compact('contacts', 'matchCounts'));
    }

    /**
     * All View — agency-wide (or branch-wide when branch-split is on) list of
     * every Core Match, for branch managers and admins to oversee what their
     * agents are doing. Gated by the `core_matches.all_view` permission.
     */
    public function allView(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // Scope: whole agency, or just the viewer's branch when branch-split is on.
        $agency   = \App\Models\Agency::find($user->effectiveAgencyId());
        $splitOn  = (bool) ($agency?->split_branches_enabled);
        $branchId = $user->effectiveBranchId();
        $branchLimited = $splitOn && $branchId;

        // Agents available in the filter dropdown.
        $agentsQuery = User::agencyMembers()
            ->where('is_active', 1)
            ->orderBy('name');
        if ($branchLimited) {
            $agentsQuery->where('branch_id', $branchId);
        }
        $agents = $agentsQuery->get(['id', 'name']);

        // Resolve the selected agent filter (ignored if outside the viewer's scope).
        $agentId = $request->query('agent_id');
        $agentId = ($agentId === null || $agentId === '' || $agentId === 'all') ? null : (int) $agentId;
        if ($agentId !== null && ! $agents->pluck('id')->contains($agentId)) {
            $agentId = null;
        }

        // ContactMatch carries BelongsToAgency, so the agency is already scoped.
        $query = ContactMatch::with(['contact.type', 'createdBy', 'feedback'])
            ->whereHas('contact')
            ->orderByRaw("FIELD(status,'active','paused','fulfilled','expired')")
            ->latest();

        if ($branchLimited) {
            $query->whereHas('createdBy', fn ($q) => $q->where('branch_id', $branchId));
        }
        if ($agentId !== null) {
            $query->where('created_by_user_id', $agentId);
        }

        $allMatches  = $query->get();
        $matchCounts = $this->propertyCountsFor($allMatches);

        // Group by owning agent for the oversight view.
        $byAgent = $allMatches->groupBy('created_by_user_id')
            ->map(fn ($items) => [
                'agent'   => $items->first()->createdBy,
                'matches' => $items,
            ])
            ->sortBy(fn ($row) => $row['agent']?->name ?? 'zzz')
            ->values();

        return view('corex.core-matches.all', compact(
            'byAgent', 'matchCounts', 'agents', 'agentId', 'branchLimited'
        ));
    }

    /**
     * Per-match property counts (total resolved / visible / hidden), keyed by
     * match id. Resolved agency-wide so the figures line up with the results page.
     *
     * @param  \Illuminate\Support\Collection<int,ContactMatch>  $matches
     * @return array<int,array{total:int,visible:int,hidden:int}>
     */
    private function propertyCountsFor($matches): array
    {
        $counts = [];
        foreach ($matches as $m) {
            $resolved  = $this->matching->propertiesForMatch($m, [
                'agent_id'       => null,
                'include_hidden' => true,
            ]);
            $hiddenIds = $m->hidden_property_ids ?? [];
            $hidden    = $resolved->filter(fn ($p) => in_array($p->id, $hiddenIds, true))->count();
            $counts[$m->id] = [
                'total'   => $resolved->count(),
                'hidden'  => $hidden,
                'visible' => $resolved->count() - $hidden,
            ];
        }
        return $counts;
    }

    public function store(Request $request, Contact $contact)
    {
        $data = $this->validatePayload($request);
        $data['contact_id']         = $contact->id;
        $data['created_by_user_id'] = auth()->id();
        $data['agency_id']          = $contact->agency_id;

        $match = ContactMatch::create($data);

        // Part 1.5 — manual capture rides the SAME cascade as a portal lead: creating
        // the ContactMatch auto-lands the buyer (ContactMatchObserver) and feeds MIC;
        // we only tag the source so MIC demand stays attributable (manual vs portal).
        app(\App\Services\Buyers\BuyerLeadCascadeService::class)
            ->tagBuyerSource($contact, \App\Services\Buyers\BuyerLeadCascadeService::SOURCE_MANUAL);

        return redirect()->route('corex.contacts.matches.results', [$contact, $match]);
    }

    public function update(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $match->update($this->validatePayload($request));

        return redirect()->route('corex.contacts.matches.results', [$contact, $match])
            ->with('success', 'Match updated.');
    }

    public function setStatus(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $status = $request->validate([
            'status' => 'required|in:active,paused,fulfilled,expired',
        ])['status'];

        $match->update(['status' => $status]);
        return back()->with('success', "Match marked {$status}.");
    }

    public function results(\Illuminate\Http\Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        // Use the strict ClientMatchResolver so the agent web view applies the
        // same hard filters as the mobile client API — sale matches never show
        // rentals, and vice versa. Spec: .ai/specs/client-auth.md (round 4).
        // includeHidden: true — the agent must still see hidden properties so
        // they can review the hide reason and un-hide them.
        $properties = app(\App\Services\Matching\ClientMatchResolver::class)->resolve($match, includeHidden: true);
        $feedback   = $match->feedback()->get()->keyBy('property_id');

        return view('corex.contacts.match-results', compact(
            'contact', 'match', 'properties', 'feedback'
        ));
    }

    public function toggleHide(Request $request, Contact $contact, ContactMatch $match, int $property)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        // Resolve through the scoped model so only an in-agency property id can be
        // hidden/stored — the raw {property} route int otherwise lets any id through.
        $property = (int) Property::query()->whereKey($property)->value('id');
        abort_if($property === 0, 404);

        if ($match->isPropertyHidden($property)) {
            $match->unhideProperty($property);
        } else {
            $data = $request->validate([
                'reason' => 'required|string|min:3|max:500',
            ], [], ['reason' => 'reason']);
            $match->hidePropertyWithReason($property, $data['reason']);
        }

        return back();
    }

    public function destroy(Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);
        $match->delete();

        return redirect()->route('corex.contacts.show', $contact)
            ->with('success', 'Match removed.')
            ->withFragment('tab-matches');
    }

    /**
     * Deal bridge — turn a (match, property) pair into a draft Deal.
     */
    public function convertToDeal(Request $request, Contact $contact, ContactMatch $match, int $property)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        // Resolve through the scoped Property model. The {property} route segment
        // is a raw int; without this an attacker could write another agency's
        // property id straight into a new Deal (cross-tenant FK injection).
        $propertyModel = Property::query()->whereKey($property)->first();
        abort_if($propertyModel === null, 404);

        $deal = DB::transaction(function () use ($contact, $match, $propertyModel) {
            $deal = new Deal();
            $deal->property_id = $propertyModel->id;
            $deal->agency_id   = $match->agency_id;
            $deal->branch_id   = $contact->branch_id ?? null;

            // Best-effort fill of the buyer/tenant side from the contact
            $name = trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? ''));
            if (\Schema::hasColumn('deals', 'buyer_name'))   $deal->buyer_name   = $name;
            if (\Schema::hasColumn('deals', 'buyer_email'))  $deal->buyer_email  = $contact->email;
            if (\Schema::hasColumn('deals', 'buyer_phone'))  $deal->buyer_phone  = $contact->phone;
            if (\Schema::hasColumn('deals', 'deal_type'))    $deal->deal_type    = $match->listing_type === 'rental' ? 'rental' : 'sale';
            if (\Schema::hasColumn('deals', 'accepted_status')) $deal->accepted_status = 'P';
            if (\Schema::hasColumn('deals', 'agent_id'))     $deal->agent_id     = $match->created_by_user_id;
            if (\Schema::hasColumn('deals', 'created_by_user_id')) $deal->created_by_user_id = auth()->id();

            $deal->save();

            if ($request->boolean('mark_fulfilled')) {
                $match->update(['status' => ContactMatch::STATUS_FULFILLED]);
            }

            return $deal;
        });

        return redirect()->route('admin.deals.edit', $deal->id)
            ->with('success', 'Deal created from match. Complete the missing details.');
    }

    protected function validatePayload(Request $request): array
    {
        // listing_type is required when creating a fresh match; optional when
        // updating an existing one (e.g. a "Make primary" partial submit).
        $isStore     = $request->routeIs('corex.contacts.matches.store');
        $listingRule = ($isStore ? 'required' : 'nullable') . '|in:sale,rental';

        $validator = Validator::make($request->all(), [
            'name'                    => 'nullable|string|max:120',
            'listing_type'            => $listingRule,
            'is_primary'              => 'nullable|boolean',
            'category'                => 'nullable|string|max:100',
            'property_type'           => 'nullable|string|max:100',
            'property_types'          => 'nullable|array',
            'property_types.*'        => 'string|max:100',
            'price_min'               => 'nullable|integer|min:0',
            'price_max'               => 'nullable|integer|min:0',
            'beds_min'                => 'nullable|integer|min:0|max:20',
            'bedrooms_max'            => 'nullable|integer|min:0|max:20',
            'baths_min'               => 'nullable|integer|min:0|max:20',
            'garages_min'             => 'nullable|integer|min:0|max:20',
            'parking_min'             => 'nullable|integer|min:0|max:20',
            'floor_size_min'          => 'nullable|integer|min:0',
            'floor_size_max'          => 'nullable|integer|min:0',
            'erf_size_min'            => 'nullable|integer|min:0',
            'erf_size_max'            => 'nullable|integer|min:0',
            'p24_suburb_ids'          => 'nullable|array',
            'p24_suburb_ids.*'        => 'integer|exists:p24_suburbs,id',
            'must_have_features'      => 'nullable|array',
            'must_have_features.*'    => 'string|max:60',
            'nice_to_have_features'   => 'nullable|array',
            'nice_to_have_features.*' => 'string|max:60',
            'deal_breakers'           => 'nullable|array',
            'deal_breakers.*'         => 'string|max:60',
            'notes'                   => 'nullable|string|max:500',
        ]);

        // Cross-field: bedrooms_max must be >= beds_min when both are present (spec D4).
        $validator->after(function ($v) {
            $bedsMin = $v->getData()['beds_min'] ?? null;
            $bedsMax = $v->getData()['bedrooms_max'] ?? null;
            if ($bedsMin !== null && $bedsMax !== null && (int) $bedsMax < (int) $bedsMin) {
                $v->errors()->add('bedrooms_max', 'Maximum bedrooms cannot be less than minimum bedrooms.');
            }
        });

        $data = $validator->validate();

        // Normalise P24 suburb id input — unique, integer, drop zeros.
        if (isset($data['p24_suburb_ids']) && is_array($data['p24_suburb_ids'])) {
            $data['p24_suburb_ids'] = array_values(array_unique(array_filter(array_map('intval', $data['p24_suburb_ids']))));
        }

        // Normalise feature arrays — trim, lowercase tokens, drop blanks.
        foreach (['must_have_features', 'nice_to_have_features', 'deal_breakers'] as $field) {
            if (isset($data[$field]) && is_array($data[$field])) {
                $data[$field] = array_values(array_unique(array_filter(array_map(
                    fn ($v) => strtolower(trim((string) $v)),
                    $data[$field]
                ))));
            }
        }

        // property_type <-> property_types reconciliation (spec D2 deprecation window).
        // - If property_types (array) is submitted, set property_type to the first element
        //   so the legacy column stays populated for one release cycle.
        // - If only legacy property_type was submitted, mirror it into property_types
        //   so new consumers see consistent shape.
        if (isset($data['property_types']) && is_array($data['property_types'])) {
            $data['property_types'] = array_values(array_filter(array_map(
                fn ($v) => trim((string) $v),
                $data['property_types']
            )));
            $data['property_type'] = $data['property_types'][0] ?? null;
        } elseif (!empty($data['property_type'])) {
            $data['property_types'] = [$data['property_type']];
        }

        return $data;
    }
}
