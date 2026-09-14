<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Deal;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\Scopes\ContactScope;
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

    /**
     * Pool-ownership chip options for the wishlist criteria form — separate
     * from FEATURE_OPTIONS on purpose. FEATURE_OPTIONS is also read by the AI
     * photo-vision suggestion services (PropertyAiSuggestionService,
     * VisionRecognitionService) as the vocabulary a single photo may be
     * classified against; "own pool" vs "communal/complex pool" is a
     * structural fact (which Spaces "Type" the agent tagged), never something
     * a photo alone can tell apart, so it must never enter that AI vocabulary.
     * Property::poolTokens() is the actual source of truth these tokens are
     * matched against — see MatchingService::propertyFeatureTokens().
     */
    public const POOL_TYPE_OPTIONS = [
        'pool_own',
        'pool_communal',
    ];

    public function __construct(protected MatchingService $matching) {}

    /**
     * "Mine" entry point — always scope=own, no selector shown. Same
     * rendering path as allView() below; the only difference is which
     * scope this route is willing to default to and which permission
     * gates it. See renderBoard()'s own docblock for why the two entry
     * points share one query/view implementation now instead of two.
     */
    public function index(Request $request)
    {
        return $this->renderBoard($request, defaultScope: 'own');
    }

    /**
     * "All View" entry point — agency managers/admins. Same rendering path
     * as index() above; defaults to the widest scope this viewer holds
     * (agency, or branch if that's all `core_matches.all_view` earns them
     * once branch-split is on) rather than a hardcoded 'agency', so a BM
     * on a branch-split agency lands on a scope they can actually see.
     */
    public function allView(Request $request)
    {
        return $this->renderBoard($request, defaultScope: 'agency');
    }

    /**
     * The Core Matches board, TASK 1 rebuild. One query-building path, one
     * view, for both entry points — Johan's own design standard (BUILD_
     * STANDARD.md §1c) already says visibility level is "permission-driven,
     * decided at spec time per screen," which is a single screen with a
     * scope control, not two hand-maintained screens. Full reasoning:
     * .ai/specs/core-matches.md, "Screen & scoping".
     *
     * Route middleware is UNCHANGED on purpose (the access_contacts vs
     * core_matches.view vs access_core_matches mismatch across the four
     * routes is reported, not fixed here — conductor's call). What
     * changes is everything downstream of "the request reached this
     * method": scope is resolved and enforced HERE, at the query layer,
     * not by which URL the request came in on.
     */
    private function renderBoard(Request $request, string $defaultScope)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        // AT-401 — unchanged lock mechanism: detected by route NAME (never
        // client-supplied), applied after the query string is read so a
        // hand-edited ?listing_type=sale on a Rentals entry is overridden,
        // not trusted.
        $indexRouteName = $request->route()->getName();
        $isRentalEntry  = in_array($indexRouteName, [
            'corex.rentals.core-matches.index', 'corex.rentals.core-matches.all',
        ], true);
        $isAllRoute = in_array($indexRouteName, [
            'corex.core-matches.all', 'corex.rentals.core-matches.all',
        ], true);
        $counterpartRouteName = match ($indexRouteName) {
            'corex.rentals.core-matches.index' => 'corex.rentals.core-matches.all',
            'corex.rentals.core-matches.all'   => 'corex.rentals.core-matches.index',
            'corex.core-matches.all'           => 'corex.core-matches.index',
            default                            => 'corex.core-matches.all',
        };

        session(['corex.lens.core_matches' => $isRentalEntry]);

        $listingType = $request->query('listing_type', '');
        if ($isRentalEntry) {
            $listingType = 'rental';
        }

        // SCOPE — resolved and enforced here, not trusted from the query
        // string, not inferred from which route the request arrived on.
        // A user without core_matches.all_view asking for branch/agency
        // silently narrows to 'own' — same precedent as the agent_id
        // filter below ("ignored if outside the viewer's scope"), not a
        // 403: this is a visibility floor, not an authorisation wall.
        $canSeeAll = $user->hasPermission('core_matches.all_view');
        $agency    = \App\Models\Agency::find($user->effectiveAgencyId());
        $splitOn   = (bool) ($agency?->split_branches_enabled);
        $branchId  = $user->effectiveBranchId();

        $availableScopes = ['own'];
        if ($canSeeAll) {
            if ($splitOn && $branchId) {
                $availableScopes[] = 'branch';
            }
            $availableScopes[] = 'agency';
        }

        $requestedScope = $request->query('scope', $isAllRoute ? $defaultScope : 'own');
        $scope = in_array($requestedScope, $availableScopes, true) ? $requestedScope : 'own';

        // SEARCH — contact name/phone/email. Never against criteria fields;
        // those are filters, not a search (BUILD_STANDARD.md §1b).
        $search = trim((string) $request->query('q', ''));

        // FILTERS — status, date range (on the match's own saved date).
        $statusFilter = $request->query('status', '');
        if (! in_array($statusFilter, ['', ContactMatch::STATUS_ACTIVE, ContactMatch::STATUS_PAUSED, ContactMatch::STATUS_FULFILLED, ContactMatch::STATUS_EXPIRED], true)) {
            $statusFilter = '';
        }
        $savedFrom = $request->query('saved_from', '');
        $savedTo   = $request->query('saved_to', '');

        // Agent filter — manager scopes only, same validation precedent as
        // before (an id outside the viewer's own agent list is dropped,
        // not trusted).
        $agents  = collect();
        $agentId = null;
        if ($scope !== 'own') {
            $agentsQuery = User::agencyMembers()->where('is_active', 1)->orderBy('name');
            if ($scope === 'branch') {
                $agentsQuery->where('branch_id', $branchId);
            }
            $agents  = $agentsQuery->get(['id', 'name']);
            $agentId = $request->query('agent_id');
            $agentId = ($agentId === null || $agentId === '' || $agentId === 'all') ? null : (int) $agentId;
            if ($agentId !== null && ! $agents->pluck('id')->contains($agentId)) {
                $agentId = null;
            }
        }

        // SORT — default stays the deliberate status-priority order
        // (active > paused > fulfilled > expired), now expressed at the
        // CONTACT level (a contact's "rank" is its best-ranked match) so a
        // manager scanning the board still sees the most actionable
        // buyers first. Two selectable alternates.
        $sort = $request->query('sort', 'priority');
        if (! in_array($sort, ['priority', 'saved', 'contact'], true)) {
            $sort = 'priority';
        }

        // Base match-level constraints, reused both to filter which
        // contacts qualify and to load only the matching matches per
        // contact — kept in one closure so the two never drift apart.
        $matchConstraints = function ($q) use ($listingType, $statusFilter, $savedFrom, $savedTo, $scope, $branchId, $agentId) {
            // Same ContactScope bypass as the contacts query below, and for
            // the same reason: whereHas('contact') builds an EXISTS
            // subquery against Contact's own default scopes, which would
            // otherwise re-narrow an oversight scope back down to the
            // viewer's personal Contacts-module visibility.
            $q->whereHas('contact', fn ($q2) => $scope !== 'own' ? $q2->withoutGlobalScope(ContactScope::class) : $q2)
                ->when($listingType !== '', fn ($q2) => $q2->where('listing_type', $listingType))
                ->when($statusFilter !== '', fn ($q2) => $q2->where('status', $statusFilter))
                ->when($savedFrom !== '', fn ($q2) => $q2->whereDate('created_at', '>=', $savedFrom))
                ->when($savedTo !== '', fn ($q2) => $q2->whereDate('created_at', '<=', $savedTo));

            if ($scope === 'own') {
                $q->where('created_by_user_id', auth()->id());
            } elseif ($scope === 'branch') {
                $q->whereHas('createdBy', fn ($q2) => $q2->where('branch_id', $branchId));
            }
            // scope === 'agency': no extra constraint — ContactMatch's own
            // BelongsToAgency global scope is the outer boundary already.

            if ($agentId !== null) {
                $q->where('created_by_user_id', $agentId);
            }
        };

        // CONTACT-LEVEL query — paginated here, not the raw match list, so
        // a contact's matches never split across a page boundary and the
        // page size actually bounds something meaningful on a 150+-row
        // agency (Johan's own figure).
        // ContactScope is a DIFFERENT module's visibility rule (the
        // Contacts screen's own role-based own/branch/all), driven by a
        // permission this controller never checks. Left in place, it
        // would silently re-narrow a manager's Core Matches 'branch'/
        // 'agency' oversight (granted by core_matches.all_view) back down
        // to whatever their unrelated Contacts-module scope happens to
        // be — exactly the kind of accidental, inconsistent scoping this
        // rebuild exists to remove. Bypassed here only for scope !== 'own'
        // — the documented "admin oversight query" case ContactScope's
        // own docblock names — never for an ordinary agent's own board,
        // which stays under Contacts' own visibility rules like every
        // other screen. See .ai/specs/core-matches.md, "Screen & scoping".
        $contactsQuery = Contact::query()
            ->when($scope !== 'own', fn ($q) => $q->withoutGlobalScope(ContactScope::class))
            ->whereHas('matches', $matchConstraints)
            ->when($search !== '', function ($q) use ($search) {
                $q->where(function ($q2) use ($search) {
                    $q2->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            })
            ->with('type')
            ->withCount('contactNotes');

        if ($sort === 'saved') {
            $contactsQuery->withMax(['matches as core_matches_latest_saved' => $matchConstraints], 'created_at')
                ->orderByDesc('core_matches_latest_saved');
        } elseif ($sort === 'contact') {
            // Longest-since-contact first; never-contacted floats to the
            // very top (nulls-first is MySQL's default ASC behaviour).
            $contactsQuery->orderBy('last_contacted_at');
        } else {
            // Status-priority at the contact level: a contact with at
            // least one ACTIVE match ranks above one with only paused
            // matches, etc. — the same FIELD() ordering the match rows
            // already use, generalised to "does this contact have one of
            // these" rather than sorting raw match rows. Each existence
            // check reuses $matchConstraints, not a bare status lookup —
            // otherwise a contact could rank as "has an active match"
            // because of a match the current filters have hidden.
            foreach ([ContactMatch::STATUS_ACTIVE, ContactMatch::STATUS_PAUSED, ContactMatch::STATUS_FULFILLED] as $i => $rankStatus) {
                $flag = "core_matches_has_{$i}";
                $contactsQuery->withExists(['matches as ' . $flag => function ($q) use ($matchConstraints, $rankStatus) {
                    $matchConstraints($q);
                    $q->where('status', $rankStatus);
                }])->orderByDesc($flag);
            }
        }
        $contactsQuery->orderBy('first_name');

        $perPage  = 25;
        $contacts = $contactsQuery->paginate($perPage)->withQueryString();

        // Load matches for ONLY this page's contacts — the whole point of
        // paginating at the contact level.
        $pageContacts   = collect($contacts->items())->keyBy('id');
        $pageContactIds = $pageContacts->keys();
        // 'contact' is NOT eager-loaded here on purpose — that would
        // re-run Contact's own query (and its ContactScope) a second
        // time, undoing the bypass above for a manager's branch/agency
        // view. The Contact models already loaded on this page (correctly
        // scoped) are reused below instead of fetched twice.
        $allMatches = ContactMatch::with(['createdBy', 'feedback'])
            ->whereIn('contact_id', $pageContactIds)
            ->tap($matchConstraints)
            ->orderByRaw("FIELD(status,'active','paused','fulfilled','expired')")
            ->latest()
            ->get()
            ->each(fn ($match) => $match->setRelation('contact', $pageContacts->get($match->contact_id)));

        $matchCounts  = $this->propertyCountsFor($allMatches);
        $matchesByContact = $allMatches->groupBy('contact_id');

        // TASK 2 fields tied to cc4's data layer — guarded on the column
        // actually existing, so this activates the moment cc4 lands it
        // rather than needing a follow-up change here. Never displayed
        // until the guard passes (view checks the same flags).
        $hasAgentColumn = \Schema::hasColumn('contact_matches', 'agent_id');
        $assignedAgentNames = collect();
        if ($hasAgentColumn) {
            $agentIds = $allMatches->pluck('agent_id')->filter()->unique();
            $assignedAgentNames = User::whereIn('id', $agentIds)->pluck('name', 'id');
        }

        // Deliberately does NOT depend on a relation name on PortalLead —
        // only the column's existence, batch-resolving the user name
        // separately below. Avoids guessing a method name cc4 hasn't
        // published yet.
        $hasFirstReceivedColumn = \Schema::hasTable('portal_leads') && \Schema::hasColumn('portal_leads', 'received_by_user_id');
        $firstReceivedByContact = collect();
        $firstReceivedNames = collect();
        if ($hasFirstReceivedColumn) {
            $firstReceivedByContact = \App\Models\PortalLead::whereIn('contact_id', $pageContactIds)
                ->whereNotNull('received_by_user_id')
                ->orderBy('received_at')
                ->get(['contact_id', 'received_by_user_id', 'received_at'])
                ->unique('contact_id')
                ->keyBy('contact_id');
            $firstReceivedNames = User::whereIn('id', $firstReceivedByContact->pluck('received_by_user_id')->filter()->unique())
                ->pluck('name', 'id');
        }

        // Johan's own addition, not gated on cc4 at all — the properties a
        // portal lead arrived on, via PortalLead::listing() (the actual
        // relation name on this model — belongsTo(Property::class,
        // 'listing_id'), confirmed against the model, not guessed from
        // the column name). Grouped per contact, deduplicated by property.
        $leadPropertiesByContact = \App\Models\PortalLead::whereIn('contact_id', $pageContactIds)
            ->whereNotNull('listing_id')
            ->with('listing:id,title,suburb,price,listing_type')
            ->get()
            ->groupBy('contact_id')
            ->map(fn ($leads) => $leads->pluck('listing')->filter()->unique('id')->values());

        $hasWorkingWindowSetting = \Schema::hasTable('core_match_settings') && \Schema::hasColumn('core_match_settings', 'working_window_days');
        $workingWindowDays = null;
        if ($hasWorkingWindowSetting) {
            $workingWindowDays = \DB::table('core_match_settings')
                ->where('agency_id', $user->effectiveAgencyId())
                ->value('working_window_days');
        }

        $rows = collect($contacts->items())->map(fn ($c) => [
            'contact' => $c,
            'matches' => $matchesByContact->get($c->id, collect()),
            'firstReceived' => $firstReceivedByContact->get($c->id),
            'leadProperties' => $leadPropertiesByContact->get($c->id, collect()),
        ]);

        $totalMatches = $allMatches->count();

        return view('corex.core-matches.index', compact(
            'rows', 'contacts', 'matchCounts', 'totalMatches',
            'listingType', 'isRentalEntry', 'isAllRoute', 'indexRouteName', 'counterpartRouteName',
            'scope', 'availableScopes', 'canSeeAll', 'agents', 'agentId', 'branchId', 'splitOn',
            'search', 'statusFilter', 'savedFrom', 'savedTo', 'sort',
            'hasAgentColumn', 'assignedAgentNames', 'hasFirstReceivedColumn', 'firstReceivedNames',
            'hasWorkingWindowSetting', 'workingWindowDays',
        ));
    }

    /**
     * Per-match property counts (total resolved / visible / hidden), keyed by
     * match id. Resolved agency-wide so the figures line up with the results page.
     *
     * Batched via MatchingService::propertyCountsForMatches() — this used to
     * call propertiesForMatch() once per match (one-to-three queries EACH),
     * which on a page with hundreds of matches was the page's N+1 (measured
     * ~12.9s wall / 1,154 queries for one agency). See that method's doc
     * comment for the correctness note on why the per-match SQL filtering was
     * ported to a PHP mirror instead of shared verbatim.
     *
     * @param  \Illuminate\Support\Collection<int,ContactMatch>  $matches
     * @return array<int,array{total:int,visible:int,hidden:int}>
     */
    private function propertyCountsFor($matches): array
    {
        return $this->matching->propertyCountsForMatches($matches);
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

    /**
     * AT-240 — render the edit surface for an existing wishlist/criteria match.
     *
     * The edit FLOW already existed end-to-end — `_match-form` supports edit
     * mode (`$isEdit` → pre-fills from $match, PUTs to matches.update) and
     * update() persists the full validated payload. What was missing was any
     * ENTRY POINT: no GET door and no Edit button on any of the surfaces that
     * render a saved match. This is that door; the four render sites now link
     * here. Supplies the same option data the create form receives on the
     * contact page (matchCategories / matchTypes / featureOptions) so the
     * reused partial renders identically.
     */
    public function edit(Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $matchCategories = PropertySettingItem::group('category')->get();
        $matchTypes      = PropertySettingItem::group('property_type')->where('active', true)->get();
        $featureOptions  = array_merge(self::FEATURE_OPTIONS, self::POOL_TYPE_OPTIONS);

        return view('corex.contacts.match-edit', compact(
            'contact', 'match', 'matchCategories', 'matchTypes', 'featureOptions'
        ));
    }

    public function update(Request $request, Contact $contact, ContactMatch $match)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        $data = $this->validatePayload($request);

        // AT-401 — a wishlist's listing_type is set once at creation and never
        // switchable via edit, in any context: the criteria fields (property
        // types, price bands, etc.) mean something different for a buyer than
        // a tenant, and the edit form itself no longer renders a togglable
        // control (_match-form.blade.php), so this is the authoritative lock,
        // not a UI nicety. Any listing_type in the submitted payload is
        // ignored; the match keeps whatever it already was.
        $data['listing_type'] = $match->listing_type;

        $match->update($data);

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

    /**
     * Print / Download PDF — the buyer's resolved wishlist property list as a
     * clean, compact A4 (landscape) sheet for working on paper during
     * appointment rounds. INTERNAL document (seller PII + addresses).
     *
     * Mirrors results() exactly: same match/contact ownership guard, same
     * ClientMatchResolver list (so the sheet reflects the active on-screen
     * list — same wishlist filters, same match_score sort). Streams inline by
     * default so the browser's print dialog is one click away; ?dl=1 forces a
     * file download.
     *
     * ?photos=0 → compact text-only sheet (no photos: faster to print, saves
     * ink, denser). Default (photos absent or =1) embeds each property's photo.
     */
    public function printList(Request $request, Contact $contact, ContactMatch $match, \App\Services\Matching\CoreMatchListPdfService $pdfService)
    {
        abort_if($match->contact_id !== $contact->id, 403);

        // Default: only the properties still in play (hidden excluded), matching
        // the visible tiles. ?include_hidden=1 keeps hidden ones (flagged).
        $includeHidden = $request->boolean('include_hidden');
        // With-photos by default; ?photos=0 for the text-only variant.
        $withPhotos    = $request->boolean('photos', true);

        $pdf      = $pdfService->pdf($contact, $match, $includeHidden, $withPhotos);
        $filename = $pdfService->filename($contact, $match, $withPhotos);

        return $request->boolean('dl')
            ? $pdf->download($filename)
            : $pdf->stream($filename);
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
            // AT-CM-clear-fix — marker the FULL criteria form always renders (see
            // _match-form.blade.php). Distinguishes "the agent cleared every item in
            // this group" (form present, group absent — unchecking a chip/checkbox
            // group submits nothing at all, unlike a text input) from a genuine
            // partial submit that never touched these fields, e.g. the "Make Primary"
            // mini-form above which posts only is_primary. See the defaulting block
            // below — only fires when this marker is present.
            'criteria_groups_present' => 'sometimes',
        ]);

        // Cross-field: bedrooms_max must be >= beds_min when both are present (spec D4).
        $validator->after(function ($v) {
            $bedsMin = $v->getData()['beds_min'] ?? null;
            $bedsMax = $v->getData()['bedrooms_max'] ?? null;
            if ($bedsMin !== null && $bedsMax !== null && (int) $bedsMax < (int) $bedsMin) {
                $v->errors()->add('bedrooms_max', 'Maximum bedrooms cannot be less than minimum bedrooms.');
            }

            // A feature can be in only ONE bucket. The form enforces this (one selector per feature),
            // so this is a backstop for any non-form/bypassed submission — reject rather than silently
            // pick a bucket, keeping the three arrays disjoint (the matching engine relies on it).
            $conflicts = \App\Models\ContactMatch::conflictingFeatureTokens(
                $v->getData()['must_have_features'] ?? [],
                $v->getData()['nice_to_have_features'] ?? [],
                $v->getData()['deal_breakers'] ?? [],
            );
            if ($conflicts) {
                $v->errors()->add('must_have_features', 'Each feature can be in only one category (Must-have, Nice, or Deal-breaker). In two: ' . implode(', ', $conflicts) . '.');
            }
        });

        $data = $validator->validate();

        // AT-CM-clear-fix — see the criteria_groups_present rule above. A browser
        // submits NOTHING for a checkbox/chip group with every item unchecked, so
        // $validator->validate() legitimately omits these keys — and $match->update()
        // correctly leaves an omitted key untouched, which is exactly right for a
        // genuine partial submit. It is wrong here: the full form always renders all
        // five of these groups, so an absent one means the agent cleared it, not that
        // they never saw it. Only default when the full-form marker is present, so a
        // partial submit (Make Primary) is never affected — it never sends the marker.
        if ($request->has('criteria_groups_present')) {
            foreach (['property_types', 'p24_suburb_ids', 'must_have_features', 'nice_to_have_features', 'deal_breakers'] as $group) {
                if (!isset($data[$group])) {
                    $data[$group] = [];
                }
            }
        }

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
