<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use App\Models\DocumentType;
use App\Models\PropertySettingItem;
use App\Models\PerformanceSetting;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    use \App\Http\Controllers\Concerns\EnforcesMarketingReadiness;
    use \App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
    use \App\Http\Concerns\AppliesP24Location;

    public function index(Request $request)
    {
        /** @var User $user */
        $user           = auth()->user();
        $dataScope      = PermissionService::getDataScope($user, 'properties');
        $canPickAgent   = in_array($dataScope, ['all', 'branch']);

        // Agency-wide default ordering (Settings → Properties). 'status_priority'
        // orders by the admin-defined status sequence; otherwise newest first.
        $agency          = $user?->effectiveAgencyId() ? \App\Models\Agency::find($user->effectiveAgencyId()) : null;
        $agencySortMode  = $agency?->properties_sort_mode ?? 'created';
        $defaultSort     = $agencySortMode === 'status_priority' ? 'status_priority' : 'newest';

        // ── Filter persistence ────────────────────────────────────────────
        // The whole active filter set (agents, status, search, every advanced
        // filter) survives navigation for the life of the browser session —
        // it is remembered until the user clears it or the session ends. A
        // bare visit that carries no filter signal but has a saved set is
        // redirected to the canonical URL so links, chips and pagination all
        // carry the state. This replaces the previous behaviour that silently
        // reset to "my listings" on any nav that dropped ?agent_id=.
        $SESSION_KEY = 'corex.properties.filters';
        $FILTER_KEYS = [
            'status', 'search', 'listing_type', 'property_type', 'category',
            'mandate_type', 'branch_id', 'price_min', 'price_max',
            'beds_min', 'baths_min', 'sort', 'dir', 'agent_ids',
        ];

        // Explicit reset — "Clear all" / "Clear filters" hit ?clear=1.
        if ($request->boolean('clear')) {
            $request->session()->forget($SESSION_KEY);
            return redirect()->route('corex.properties.index');
        }

        // Did this request carry any filter signal? (incl. the legacy single
        // ?agent_id and the compliance click-through ?filter=marketing_pending)
        $hasFilterParam = $request->has('agent_id')
            || $request->query('filter') === 'marketing_pending'
            || collect($FILTER_KEYS)->contains(fn ($k) => $request->has($k));

        // Bare visit + saved state → restore by redirecting to the canonical URL.
        if (! $hasFilterParam) {
            $saved = (array) $request->session()->get($SESSION_KEY, []);
            if (! empty($saved)) {
                return redirect()->route('corex.properties.index', $saved);
            }
        }

        $viewScope      = $request->query('scope', 'my');   // 'my' | 'branch'
        $status         = $request->query('status', '');    // '' | draft | active | sold | withdrawn
        $search         = trim($request->query('search', ''));

        // Extended filters
        $listingType    = $request->query('listing_type', '');   // '' | sale | rental
        $propertyType   = $request->query('property_type', '');
        $category       = $request->query('category', '');
        $mandateType    = $request->query('mandate_type', '');
        $branchFilter   = $request->query('branch_id', '');
        $priceMin       = $request->query('price_min', '');
        $priceMax       = $request->query('price_max', '');
        $bedsMin        = $request->query('beds_min', '');
        $bathsMin       = $request->query('baths_min', '');
        $sort           = $request->query('sort', $defaultSort);  // newest|oldest|price_asc|price_desc|title|status_priority

        $query = Property::with(['agent', 'branch', 'secondAgent']);

        // ── Agent multi-select ────────────────────────────────────────────
        // agent_ids = comma list of ids | 'all' | (absent). Falls back to the
        // legacy single ?agent_id, then to the session, then to the user's own
        // listings on a true fresh visit. An empty list ⇒ no agent restriction
        // ("All agents", within the user's data scope).
        $filterAgentIds = [];          // empty ⇒ All agents
        if ($canPickAgent) {
            if ($request->has('agent_ids')) {
                $filterAgentIds = $this->parseAgentIds($request->query('agent_ids', ''));
            } elseif ($request->has('agent_id')) {
                $aid = (string) $request->query('agent_id', '');
                $filterAgentIds = ($aid !== '' && ctype_digit($aid)) ? [$aid] : [];
            } elseif ($request->query('filter') === 'marketing_pending') {
                $filterAgentIds = [];   // compliance click-through ⇒ full scope
            } else {
                // Explicit-filter request without an agent signal: keep what the
                // session remembers, else default to the user's own listings.
                $saved = (array) $request->session()->get($SESSION_KEY, []);
                $filterAgentIds = array_key_exists('agent_ids', $saved)
                    ? $this->parseAgentIds((string) $saved['agent_ids'])
                    : [(string) $user->id];
            }
        }

        // Scope
        // Co-listing rule: a property may carry a secondary (co-listing) agent
        // on `pp_second_agent_id`. Wherever a listing is scoped to an agent, it
        // matches whether that agent is the PRIMARY (`agent_id`) or the SECONDARY
        // — so a co-listed property appears under both agents' names. A property
        // is a single row, so an `OR` match still returns it exactly once even
        // when both the primary and secondary are in the selected set.
        if ($canPickAgent && ! empty($filterAgentIds)) {
            // Admin/BM viewing one or more specific agents
            $ids = array_map('intval', $filterAgentIds);
            $query->where(function ($q) use ($ids) {
                $q->whereIn('agent_id', $ids)
                  ->orWhereIn('pp_second_agent_id', $ids);
            });
        } elseif ($canPickAgent) {
            // All agents — still bounded by the user's data scope
            if ($dataScope === 'branch') {
                $branchId = $user->effectiveBranchId();
                if ($branchId) $query->where('branch_id', $branchId);
            }
            // dataScope 'all' ⇒ no restriction
        } else {
            // Agent: 'my' = own listings only; 'branch' = all branch listings
            if ($viewScope === 'branch' && $user->branch_id) {
                $query->where('branch_id', $user->branch_id);
            } else {
                $query->where(function ($q) use ($user) {
                    $q->where('agent_id', $user->id)
                      ->orWhere('pp_second_agent_id', $user->id);
                });
            }
        }

        // Remember the active filter set for this session (only on an explicit
        // filter interaction — a pure-default visit stays unsaved).
        if ($hasFilterParam) {
            $persist = [];
            foreach (['status', 'search', 'listing_type', 'property_type', 'category',
                      'mandate_type', 'branch_id', 'price_min', 'price_max',
                      'beds_min', 'baths_min', 'sort', 'dir'] as $k) {
                $v = $request->query($k);
                if ($v !== null && $v !== '') $persist[$k] = $v;
            }
            if ($canPickAgent) {
                $persist['agent_ids'] = empty($filterAgentIds) ? 'all' : implode(',', $filterAgentIds);
            }
            $request->session()->put($SESSION_KEY, $persist);
        }

        if ($status === 'published') {
            $query->whereNotNull('published_at');
        } elseif ($status === 'on_market') {
            // On-market = live stock (for_sale incl. sub-labels, under_offer, …),
            // i.e. NOT terminal/draft. Single source of truth on the model.
            $query->whereNotIn('status', Property::OFF_MARKET_STATUSES);
        } elseif ($status !== '') {
            $query->where('status', $status);
        }
        if ($listingType !== '')   $query->where('listing_type', $listingType);
        if ($propertyType !== '')  $query->where('property_type', $propertyType);
        if ($category !== '')      $query->where('category', $category);
        if ($mandateType !== '')   $query->where('mandate_type', $mandateType);
        if ($branchFilter !== '' && $canPickAgent) $query->where('branch_id', (int) $branchFilter);
        if ($priceMin !== '' && is_numeric($priceMin)) $query->where('price', '>=', (int) $priceMin);
        if ($priceMax !== '' && is_numeric($priceMax)) $query->where('price', '<=', (int) $priceMax);
        if ($bedsMin !== ''  && is_numeric($bedsMin))  $query->where('beds', '>=', (int) $bedsMin);
        if ($bathsMin !== '' && is_numeric($bathsMin)) $query->where('baths', '>=', (int) $bathsMin);

        // Marketing status filter
        $marketingFilter = $request->query('filter', '');
        if ($marketingFilter === 'marketing_pending') {
            $query->whereNull('compliance_snapshot_at')->whereNotIn('status', Property::OFF_MARKET_STATUSES);
        }

        if ($search !== '') {
            $query->searchAddress($search);
        }

        // Stats for the header KPIs — computed across the full filtered set
        // (not just the current page), before sorting/pagination is applied.
        // Single aggregate query (conditional SUMs) instead of 5 separate COUNT
        // round-trips for the same filtered set.
        // "On market" = live stock = status NOT IN the off-market terminal/draft
        // set (single source of truth on the model). Values are code constants,
        // never user input, so direct interpolation is safe.
        $offMarketIn = "'" . implode("','", Property::OFF_MARKET_STATUSES) . "'";
        $agg = (clone $query)->selectRaw(
            "COUNT(*) as total,"
            . " SUM(CASE WHEN status NOT IN ($offMarketIn) THEN 1 ELSE 0 END) as active,"
            . " SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as draft,"
            . " SUM(CASE WHEN status = 'sold' THEN 1 ELSE 0 END) as sold,"
            . " SUM(CASE WHEN published_at IS NOT NULL THEN 1 ELSE 0 END) as synced"
        )->first();
        $stats = [
            'total'  => (int) ($agg->total ?? 0),
            'active' => (int) ($agg->active ?? 0),
            'draft'  => (int) ($agg->draft ?? 0),
            'sold'   => (int) ($agg->sold ?? 0),
            'synced' => (int) ($agg->synced ?? 0),
        ];

        // Sorting — whitelisted columns only
        $dir = strtolower($request->query('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
        $sortableColumns = [
            'title' => 'title', 'suburb' => 'suburb', 'property_type' => 'property_type',
            'price' => 'price', 'beds' => 'beds', 'baths' => 'baths',
            'status' => 'status', 'created_at' => 'created_at',
        ];
        // Legacy sort param support
        switch ($sort) {
            case 'oldest':     $sort = 'created_at'; $dir = 'asc'; break;
            case 'price_asc':  $sort = 'price'; $dir = 'asc'; break;
            case 'price_desc': $sort = 'price'; $dir = 'desc'; break;
            case 'newest':     $sort = 'created_at'; $dir = 'desc'; break;
        }
        if ($sort === 'status_priority') {
            // Agency-defined status sequence: ranked statuses first (in order),
            // unranked last, newest within each. Names come from agency settings
            // (admin input) so they are bound, never interpolated.
            $priority = is_array($agency?->properties_status_priority) ? $agency->properties_status_priority : [];
            $priority = array_values(array_filter(array_map('trim', $priority), fn ($n) => $n !== ''));
            if (!empty($priority)) {
                $lower = array_map(fn ($n) => mb_strtolower($n), $priority);
                $placeholders = implode(',', array_fill(0, count($lower), '?'));
                $query->orderByRaw("FIELD(LOWER(status), $placeholders) = 0", $lower)
                      ->orderByRaw("FIELD(LOWER(status), $placeholders)", $lower)
                      ->orderByDesc('created_at');
            } else {
                $query->orderByDesc('created_at');
            }
        } elseif (isset($sortableColumns[$sort])) {
            $query->orderBy($sortableColumns[$sort], $dir);
        } else {
            $query->orderByDesc('created_at');
            $sort = 'created_at';
            $dir = 'desc';
        }

        // Page size is agency-configurable (Settings → Properties). Clamp the
        // stored value to a sane range so a missing/invalid value can't break paging.
        $perPage = (int) PerformanceSetting::get('properties_per_page', 20);
        $perPage = $perPage > 0 ? min($perPage, 200) : 20;
        $properties = $query->paginate($perPage)->withQueryString();

        // Compute marketing status per property (batch-friendly for Phase 1)
        $readinessSvc = app(\App\Services\Compliance\MarketingReadinessService::class);
        $authId = (int) ($user->id ?? 0);
        foreach ($properties as $p) {
            // Is the current viewer the SECONDARY (co-listing) agent on this
            // listing rather than the primary? Drives the "Secondary" badge so a
            // co-listed property is clearly distinguished from one the agent owns.
            $p->viewer_is_secondary = $authId
                && (int) ($p->pp_second_agent_id ?? 0) === $authId
                && (int) ($p->agent_id ?? 0) !== $authId;

            if ($p->compliance_snapshot_at !== null) {
                $p->marketing_status = 'live';
                $p->marketing_status_detail = 'Live since ' . $p->compliance_snapshot_at->format('j M Y');
            } elseif (in_array($p->status, Property::OFF_MARKET_STATUSES)) {
                $p->marketing_status = 'n/a';
                $p->marketing_status_detail = '';
            } else {
                $report = $readinessSvc->statusFor($p);
                $p->marketing_status = $report->ready ? 'ready' : 'blocked';
                $p->marketing_status_detail = $report->ready ? 'All gates passed' : implode(', ', array_map(fn ($b) => \Illuminate\Support\Str::limit($b, 30), $report->blockedBy));
            }
        }

        // Sort by marketing_status (derived — PHP sort, current page only)
        if ($sort === 'marketing_status') {
            $properties->setCollection(
                $properties->getCollection()->sortBy('marketing_status', SORT_REGULAR, $dir === 'desc')->values()
            );
        }

        // AT-188 — the current agent's own unpublished drafts, newest first.
        // Drives the "Drafts" control next to "New Property": hidden when there
        // are none, a direct continue-link when there is exactly one, and a
        // pick-list popup (title + suburb + age) when there are several — so a
        // fresh "New Property" click never silently reopens an old draft.
        $myDrafts = Property::query()
            ->where('agent_id', $user->id)
            ->where('status', 'draft')
            ->whereNull('published_at')
            ->latest('updated_at')
            ->limit(20)
            ->get(['id', 'title', 'suburb', 'updated_at']);

        // Agent list for the picker (admin/bm only)
        $agentList = $canPickAgent ? $this->agentList()->values() : collect();

        // Resolve the selected agents for the button label / chips
        $selectedAgents = ($canPickAgent && ! empty($filterAgentIds))
            ? $agentList->whereIn('id', array_map('intval', $filterAgentIds))->values()
            : collect();

        // Dropdown option lists (agency-managed via web settings)
        $filterOptions = [
            'property_types' => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'categories'     => PropertySettingItem::group('category')->get(),
            'mandate_types'  => PropertySettingItem::group('mandate_type')->get(),
            'branches'       => $canPickAgent ? Branch::orderBy('name')->get() : collect(),
        ];

        $filters = compact(
            'status', 'search', 'listingType', 'propertyType', 'category',
            'mandateType', 'branchFilter', 'priceMin', 'priceMax',
            'bedsMin', 'bathsMin', 'sort'
        );

        $scope = $viewScope;

        $currentSort = $sort;
        $currentDir = $dir;

        return view('corex.properties.index', compact(
            'properties', 'stats', 'scope', 'status', 'search',
            'filterAgentIds', 'agentList', 'selectedAgents', 'canPickAgent',
            'filterOptions', 'filters', 'currentSort', 'currentDir', 'agencySortMode',
            'myDrafts'
        ));
    }

    /**
     * Normalise an agent_ids filter value into an array of numeric id strings.
     * Accepts a comma list ("3,5"), the 'all' sentinel, or an empty value
     * (both ⇒ [] = no agent restriction).
     */
    private function parseAgentIds(string $raw): array
    {
        if ($raw === 'all' || trim($raw) === '') {
            return [];
        }

        return collect(explode(',', $raw))
            ->map(fn ($v) => trim($v))
            ->filter(fn ($v) => $v !== '' && ctype_digit($v))
            ->unique()->values()->all();
    }

    public function show(Property $property)
    {
        $this->authorizeProperty($property);
        $property->load(['agent', 'branch', 'notes.user', 'files.user', 'contacts.type']);

        $settingItems = [
            'categories'      => PropertySettingItem::group('category')->get(),
            'types'           => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'statuses'        => PropertySettingItem::group('property_status')->where('active', true)->get(),
            'mandateTypes'    => PropertySettingItem::group('mandate_type')->get(),
            // Build 3 — condition levels drive CMA Middle band adjustment.
            'conditionLevels' => PropertySettingItem::group('condition_level')->where('active', true)->get(),
        ];

        $branches = Branch::orderBy('name')->get();
        $agents   = $this->agentList();
        // An explicit ?tab= in the URL (deep links from the mobile app's
        // compliance next_actions, marketing-pack, FICA, etc.) MUST win over
        // any flashed session('tab') left by a prior redirect — otherwise a
        // sticky session tab swallows the deep link and the user always
        // lands on the last-used tab (usually contacts).
        $activeTab = request('tab') ?? session('tab') ?? 'overview';

        // Find all Core Matches where this property satisfies the criteria.
        // Hard filters run in SQL (indexed); scoring runs in PHP and the result is sorted.
        $coreMatches = $property->exists
            ? app(\App\Services\Matching\MatchingService::class)->matchesForProperty($property)
            : collect();

        // PP feed readiness check for syndication panel
        $ppMissingFields = $property->exists
            ? app(PrivatePropertyListingMapper::class)->checkReadiness($property)
            : [];

        // P24 feed readiness check for syndication panel
        $p24MissingFields = $property->exists
            ? app(Property24ListingMapper::class)->checkReadiness($property)
            : [];

        // HFC Premium readiness check (website requires agent + agent phone)
        $hfcMissingFields = [];
        if ($property->exists) {
            if (! $property->agent) {
                $hfcMissingFields[] = ['field' => 'agent', 'label' => 'Listing agent'];
            } else {
                if (empty($property->agent->phone)) {
                    $hfcMissingFields[] = ['field' => 'agent_phone', 'label' => 'Agent phone number'];
                }
                if (empty($property->agent->email)) {
                    $hfcMissingFields[] = ['field' => 'agent_email', 'label' => 'Agent email'];
                }
            }
            if (empty($property->title))   $hfcMissingFields[] = ['field' => 'title',   'label' => 'Title'];
            if (empty($property->price))   $hfcMissingFields[] = ['field' => 'price',   'label' => 'Price'];
            if (empty($property->status))  $hfcMissingFields[] = ['field' => 'status',  'label' => 'Status'];
            if (empty($property->suburb))  $hfcMissingFields[] = ['field' => 'suburb',  'label' => 'Suburb'];
        }

        // Overview tab: activity timeline from unified audit log
        $categoryColors = [
            'property' => '#94a3b8', 'compliance' => '#10b981', 'syndication' => '#3b82f6',
            'document' => '#8b5cf6', 'marketing' => '#ec4899', 'media' => '#f59e0b',
            'contact_link' => '#06b6d4', 'system' => '#64748b',
        ];
        $auditEntries = \App\Models\PropertyAuditLog::where('property_id', $property->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
        $activityTimeline = $auditEntries->map(fn ($a) => [
            'type' => $a->event_category,
            'icon' => match($a->event_category) {
                'compliance' => 'shield', 'syndication' => 'globe', 'document' => 'file',
                'marketing' => 'share', 'media' => 'camera', default => 'activity',
            },
            'label' => $a->human_summary ?? ucfirst(str_replace('_', ' ', $a->event_type)),
            'detail' => $a->user ? ('by ' . $a->user->name) : '',
            'date' => $a->created_at,
            'color' => $categoryColors[$a->event_category] ?? '#94a3b8',
        ]);
        // If no audit log entries yet, show basic created/published from property
        if ($activityTimeline->isEmpty()) {
            if ($property->published_at) {
                $activityTimeline->push(['type' => 'system', 'icon' => 'check', 'label' => 'Published to website', 'detail' => '', 'date' => $property->published_at, 'color' => '#22c55e']);
            }
            $activityTimeline->push(['type' => 'system', 'icon' => 'plus', 'label' => 'Property created', 'detail' => '', 'date' => $property->created_at, 'color' => '#94a3b8']);
        }
        // Full history for History tab
        $fullAuditLog = \App\Models\PropertyAuditLog::where('property_id', $property->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        // Drive tab: all documents linked to this property
        try {
            $allDriveDocs = $property->documents()->with(['documentType', 'contacts'])->get();
            $documentTypes = DocumentType::ordered()->get();
        } catch (\Exception $e) {
            $allDriveDocs = collect();
            $documentTypes = collect();
        }

        // Drive folders: document types applicable to this property's listing type (sale/rental)
        try {
            $listingType = $property->listing_type ?? 'sale';
            $driveFolders = DocumentType::active()->ordered()->get()
                ->filter(fn($dt) => $dt->appliesToListingType($listingType))
                ->values();
        } catch (\Exception $e) {
            $driveFolders = $documentTypes;
        }

        // CSV export for History tab
        if (request('export') === 'csv' && request('tab') === 'history') {
            $rows = \App\Models\PropertyAuditLog::where('property_id', $property->id)
                ->with('user')->orderByDesc('created_at')->get();
            $csv = "Timestamp,User,Category,Event Type,Summary,Metadata\n";
            foreach ($rows as $r) {
                $csv .= '"' . $r->created_at->toIso8601String() . '","' . addslashes($r->user?->name ?? 'System') . '","' . $r->event_category . '","' . $r->event_type . '","' . addslashes($r->human_summary ?? '') . '","' . addslashes(json_encode($r->metadata ?? [])) . "\"\n";
            }
            return response($csv, 200, [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="property-' . $property->id . '-audit-log.csv"',
            ]);
        }

        $readinessSvc = app(\App\Services\Compliance\MarketingReadinessService::class);
        $readinessReport = $readinessSvc->statusFor($property);
        // Drive-tab compliance checklist — same per-type presence the gate uses.
        $complianceChecklist = $property->exists ? $readinessSvc->complianceChecklistFor($property) : [];

        // Whistleblower compliance flags linked to this property
        $propertyComplianceComplaints = $property->exists
            ? \App\Models\Compliance\WhistleblowComplaint::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->whereIn('status', ['sent', 'acknowledged_by_ppra', 'approved'])
                ->with('reporter')
                ->orderByDesc('created_at')
                ->get()
            : collect();

        // AI photo suggestions — only when the agency has the feature on AND
        // the user may use it. Built from completed, not-yet-reviewed image
        // analyses and expressed in the web spaces/features vocabulary.
        $aiImageSuggestions = ['hasSuggestions' => false, 'spaces' => [], 'features' => []];
        $user = auth()->user();
        if ($property->exists
            && $user?->agency?->ai_image_recognition_enabled
            && $user->hasPermission('use_property_image_ai')) {
            $aiImageSuggestions = app(\App\Services\AI\PropertyAiSuggestionService::class)->forProperty($property);
        }

        // AT-158 DR2 · WS5 (§10) — Document distributions on the PROPERTY pillar.
        // DR2 outbound distributions link their Communication to the deal's
        // property; this is the property's read surface for "one send, three
        // pillars". Rows are scoped by the viewer's communications data-scope
        // (own → the sending agent, branch, all) via Communication::scopeVisibleTo
        // — a user without visibility receives no rows (not merely hidden in UI),
        // matching the contact-tab discipline (AT-118). Only outbound rows carry
        // property links today, but the query is direction-agnostic and future-proof.
        $propertyComms = collect();
        if ($property->exists && $user) {
            $commsScope = \App\Services\PermissionService::getDataScope($user, 'communications');
            $propertyComms = \App\Models\Communications\Communication::query()
                ->notPurged()
                ->with(['owner:id,name', 'attachments'])
                ->whereHas('links', function ($q) use ($property) {
                    $q->where('linkable_type', Property::class)
                      ->where('linkable_id', $property->id);
                })
                ->visibleTo($user, $commsScope)
                ->orderByDesc('occurred_at')
                ->limit(50)
                ->get();
        }

        return view('corex.properties.show', compact(
            'property', 'settingItems', 'branches', 'agents', 'activeTab', 'coreMatches', 'ppMissingFields', 'p24MissingFields', 'hfcMissingFields',
            'allDriveDocs', 'documentTypes', 'driveFolders', 'activityTimeline', 'fullAuditLog', 'readinessReport', 'complianceChecklist', 'propertyComplianceComplaints',
            'aiImageSuggestions', 'propertyComms'
        ));
    }

    public function create()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $property           = new Property();
        $property->status   = 'active';
        $property->listing_type = 'sale';
        // Province intentionally not pre-filled — user must pick from the
        // P24 cascading picker so the suburb/city/province chain is real.
        $property->agent_id = $user->id;
        $property->branch_id = $user->effectiveBranchId();
        $property->agency_id = $user->agency_id ?? null;
        $property->beds       = 0;
        $property->baths      = 0;
        $property->half_baths = 0;
        $property->garages    = 0;

        // Pre-fill from contact if creating from a contact page (AT-60).
        $preLinkedContact = null;
        $existingPropertyMatch = null;
        $heldCapturedMatch = null;
        if ($contactId = request('contact_id')) {
            $contact = \App\Models\Contact::find($contactId);
            if ($contact) {
                $preLinkedContact = $contact;

                // Field-for-field prefill from the contact's STRUCTURED address.
                // (Previously read $contact->suburb/city/province/street_address —
                // columns that never existed on contacts, so this silently no-oped.)
                $property->unit_number        = $contact->unit_number;
                $property->floor_number       = $contact->floor_number;
                $property->unit_section_block  = $contact->unit_section_block;
                $property->complex_name       = $contact->complex_name;
                $property->street_number      = $contact->street_number;
                $property->street_name        = $contact->street_name;
                $property->suburb             = $contact->suburb;
                $property->city               = $contact->city;
                $property->province           = $contact->province;
                $property->p24_province_id    = $contact->p24_province_id;
                $property->p24_city_id        = $contact->p24_city_id;
                $property->p24_suburb_id      = $contact->p24_suburb_id;

                // Match-or-create duplicate guard (non-negotiable #10): surface
                // an existing property at this address so the agent can link to
                // it instead of minting a duplicate. Aggressiveness is
                // agency-configurable (address_match_mode).
                $existingPropertyMatch = app(\App\Services\Contact\ContactAddressPropertyGuard::class)
                    ->findLinkableProperty($contact);

                // Part 3 — if there's no linkable STOCK property but we DO already hold
                // captured intelligence on this address (a tracked property not yet
                // promoted to stock), warn here too so the agent knows CoreX already
                // knows this property before they create a duplicate / canvass.
                if (! $existingPropertyMatch) {
                    $held = app(\App\Services\Contact\ContactAddressPropertyGuard::class)
                        ->findHeldForContact($contact);
                    if ($held && $held['kind'] === 'captured') {
                        $heldCapturedMatch = $held;
                    }
                }
            }
        }

        $settingItems = [
            'categories'      => PropertySettingItem::group('category')->get(),
            'types'           => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'statuses'        => PropertySettingItem::group('property_status')->where('active', true)->get(),
            'mandateTypes'    => PropertySettingItem::group('mandate_type')->get(),
            // Build 3 — condition levels drive CMA Middle band adjustment.
            'conditionLevels' => PropertySettingItem::group('condition_level')->where('active', true)->get(),
        ];
        $branches  = Branch::orderBy('name')->get();
        $agents    = $this->agentList($property);
        $activeTab = 'info';

        return view('corex.properties.show', compact('property', 'settingItems', 'branches', 'agents', 'activeTab', 'preLinkedContact', 'existingPropertyMatch', 'heldCapturedMatch'));
    }

    public function store(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();

        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'excerpt'          => 'nullable|string|max:500',
            'description'      => 'nullable|string',
            'price'            => 'required|integer|min:0',
            'price_on_application' => 'nullable|boolean',
            'has_deposit'      => 'nullable|boolean',
            'lease_period'     => 'nullable|string|max:100',
            'price_per_day'    => 'nullable|numeric|min:0',
            'price_per_week'   => 'nullable|numeric|min:0',
            'price_per_year'   => 'nullable|numeric|min:0',
            'lease_type'       => 'nullable|string|max:100',
            'gross_price'      => 'nullable|numeric|min:0',
            'net_price'        => 'nullable|numeric|min:0',
            'yard_price'       => 'nullable|numeric|min:0',
            'primary_price_display' => 'nullable|string|in:monthly,daily,weekly,yearly',
            'rates_taxes'      => 'nullable|integer|min:0',
            'levy'             => 'nullable|integer|min:0',
            'special_levy'     => 'nullable|integer|min:0',
            'city'             => 'nullable|string|max:100',
            'suburb'           => 'required|string|max:100',
            'address'          => 'nullable|string|max:300',
            'region'           => 'nullable|string|max:100',
            'latitude'         => 'nullable|numeric|between:-90,90',
            'longitude'        => 'nullable|numeric|between:-180,180',
            'p24_province_id'  => 'nullable|integer|exists:p24_provinces,id',
            'p24_city_id'      => 'nullable|integer|exists:p24_cities,id',
            'p24_suburb_id'    => 'nullable|integer|exists:p24_suburbs,id',
            'beds'             => 'required|integer|min:0|max:20',
            'baths'            => 'required|integer|min:0|max:20',
            'half_baths'       => 'nullable|integer|min:0|max:20',
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'nullable|string|max:50',
            'category'         => 'nullable|string|max:100',
            // Build 3 — agency-isolated FK to property_setting_items
            // (group='condition_level'). The DB FK enforces id existence
            // + nullOnDelete; the controller need only validate type.
            'condition_level_id' => 'nullable|integer|exists:property_setting_items,id',
            'mandate_type'     => 'nullable|string|max:50',
            'listing_type'     => 'nullable|string|in:sale,rental',
            'status'           => 'nullable|string|max:100',
            'status_label'     => 'nullable|string|max:50',
            'features'         => 'nullable|array',
            'features.*'       => 'string|max:100',
            'spaces_json'      => 'nullable|string',
            'property_number'  => 'nullable|string|max:100',
            'complex_name'     => 'nullable|string|max:255',
            'unit_number'      => 'nullable|string|max:100',
            'floor_number'     => 'nullable|string|max:50',
            'unit_section_block' => 'nullable|string|max:255',
            'stand_number'     => 'nullable|string|max:100',
            'zone_type'        => 'nullable|string|max:100',
            'address_internal_note' => 'nullable|string|max:2000',
            'street_name'      => 'nullable|string|max:255',
            'street_number'    => 'nullable|string|max:50',
            'province'         => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:255',
            'rental_amount'    => 'nullable|numeric|min:0',
            'deposit_amount'   => 'nullable|numeric|min:0',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'admin_fee'        => 'nullable|numeric|min:0',
            'marketing_fee'    => 'nullable|numeric|min:0',
            'listed_date'      => 'nullable|date',
            'expiry_date'      => 'nullable|date|after_or_equal:listed_date',
            'lease_start_date' => 'nullable|date',
            'lease_end_date'   => 'nullable|date',
            'branch_id'        => 'nullable|exists:branches,id',
            'agent_id'         => 'required|exists:users,id',
            'pp_second_agent_id' => 'nullable|exists:users,id',
            'pp_agent_image'           => 'nullable|image|max:1024',
            'pp_second_agent_image'    => 'nullable|image|max:1024',
            'youtube_video_id'   => 'nullable|string|max:500',
            'matterport_id'      => 'nullable|string|max:100',
            'virtual_tour_url'   => 'nullable|url|max:1000',
            'rental_price_type'  => 'nullable|string|max:50',
            'pp_hide_street_name'   => 'nullable|boolean',
            'pp_hide_street_number' => 'nullable|boolean',
            'pp_hide_complex_name'  => 'nullable|boolean',
            'pp_hide_unit_number'   => 'nullable|boolean',
            'p24_hide_address'      => 'nullable|boolean',
            'publish'          => 'nullable|boolean',
            'dawn_images'               => 'nullable|array',
            'dawn_images.*'             => 'image|max:512000',
            'noon_images'               => 'nullable|array',
            'noon_images.*'             => 'image|max:512000',
            'dusk_images'               => 'nullable|array',
            'dusk_images.*'             => 'image|max:512000',
            'gallery_images'            => 'nullable|array',
            'gallery_images.*'          => 'image|max:512000',
            // Create-form extras
            'initial_note'              => 'nullable|string|max:5000',
            'drive_files'               => 'nullable|array',
            'drive_files.*'             => 'file|max:51200',
            'pending_contact_ids'       => 'nullable|array',
            'pending_contact_ids.*'     => 'integer',
            'pending_new_contacts'      => 'nullable|array',
        ]);

        // A property must have at least one contact linked on creation.
        $hasContact = !empty(array_filter((array) $request->input('pending_contact_ids', [])))
            || collect((array) $request->input('pending_new_contacts', []))
                ->contains(fn ($nc) => !empty($nc['first_name']) && !empty($nc['last_name']) && !empty($nc['phone']));
        if (! $hasContact) {
            if ($request->wantsJson()) {
                return response()->json([
                    'ok'      => false,
                    'message' => 'A contact must be linked to the property before saving.',
                ], 422);
            }
            return back()
                ->withInput()
                ->withErrors(['contacts' => 'A contact must be linked to the property before saving.']);
        }

        $data = $this->processSpacesJson($data);
        $data = $this->applyP24Location($data);

        // Extract YouTube video ID from full URL if pasted
        if (!empty($data['youtube_video_id'])) {
            $data['youtube_video_id'] = self::extractYoutubeId($data['youtube_video_id']);
        }

        $storeScope = PermissionService::getDataScope($user, 'properties');
        if (! in_array($storeScope, ['all', 'branch']) || empty($data['agent_id'])) {
            $data['agent_id'] = $user->id;
        }
        $data['agency_id'] = $user->effectiveAgencyId();

        // Branch follows the primary agent — every property is owned by its agent's branch.
        // If the agent has no branch, leave whatever the form/default supplied so we don't null it out.
        $assignedAgent = User::find($data['agent_id']);
        $derivedBranchId = $assignedAgent ? ($assignedAgent->effectiveBranchId() ?? $assignedAgent->branch_id) : null;
        if ($derivedBranchId) {
            $data['branch_id'] = $derivedBranchId;
        }

        if (! empty($data['publish'])) {
            $data['published_at'] = now();
            // Clean status model: on-market status is 'active' (the stock type
            // For Sale/For Rent lives on listing_type). Promote only a draft/empty
            // placeholder to active; keep any existing on-market base + sub-label.
            $cur = $data['status'] ?? '';
            if ($cur === '' || $cur === 'draft') {
                $data['status'] = 'active';
            }
        }
        unset($data['publish']);
        // Sub-label banner is meaningful only on an on-market (active) listing.
        if (($data['status'] ?? null) !== null && $data['status'] !== 'active') {
            $data['status_label'] = null;
        }

        // `status` is NOT NULL (DB default 'draft'). An empty status field
        // arrives as null via ConvertEmptyStringsToNull — strip it so the
        // column default applies rather than violating the constraint.
        if (array_key_exists('status', $data) && ($data['status'] === null || $data['status'] === '')) {
            unset($data['status']);
        }

        // Create to get ID, then attach images. The whole multi-write sequence
        // (property + images + notes + drive files + contact links) runs in a
        // transaction so a mid-sequence failure cannot leave a half-built property
        // — e.g. created but with no linked contact, breaking the must-have-contact
        // invariant enforced above.
        $property = \DB::transaction(function () use ($request, $data) {
        $property = Property::create($data);

        if ($property->p24_suburb_id) {
            event(new \App\Events\Property\PropertySuburbLinked(
                property: $property,
                previousP24SuburbId: null,
                newP24SuburbId: (int) $property->p24_suburb_id,
                actorUserId: auth()->id(),
            ));
        }

        $property->dawn_images_json    = $this->storeImages($request, 'dawn_images',    $property->id);
        $property->noon_images_json    = $this->storeImages($request, 'noon_images',    $property->id);
        $property->dusk_images_json    = $this->storeImages($request, 'dusk_images',    $property->id);
        $property->gallery_images_json = $this->storeImages($request, 'gallery_images', $property->id);

        // Agent images for portal syndication
        if ($request->hasFile('pp_agent_image')) {
            $property->pp_agent_image_path = $request->file('pp_agent_image')->store("properties/{$property->id}/agents", 'public');
        }
        if ($request->hasFile('pp_second_agent_image')) {
            $property->pp_second_agent_image_path = $request->file('pp_second_agent_image')->store("properties/{$property->id}/agents", 'public');
        }

        $property->saveQuietly();

        // (Legacy website push-sync removed with the Agency Public API — websites
        // now pull via /api/v1/website/* + webhooks. See the audit note.)

        // Initial note (written from create form)
        if ($request->filled('initial_note')) {
            $property->notes()->create([
                'user_id' => auth()->id(),
                'content' => $request->input('initial_note'),
            ]);
        }

        // Drive files uploaded during create
        if ($request->hasFile('drive_files')) {
            foreach ($request->file('drive_files') as $file) {
                $path = $file->store("properties/{$property->id}/files", 'public');
                $property->files()->create([
                    'user_id'   => auth()->id(),
                    'name'      => $file->getClientOriginalName(),
                    'path'      => $path,
                    'size'      => $file->getSize(),
                    'mime_type' => $file->getMimeType(),
                ]);
            }
        }

        // Contacts captured while creating a LISTING are the seller side of the
        // deal, so default their pivot role to the listing-based seller role
        // (sale → seller, rental → landlord) instead of NULL. A NULL role here
        // is invisible to the compliance gate's seller/FICA check — the root
        // cause of "no sellers linked" on freshly-created properties.
        $defaultLinkRole = ($property->listing_type ?? 'sale') === 'rental' ? 'landlord' : 'seller';

        // Link existing contacts selected during create
        foreach ((array) $request->input('pending_contact_ids', []) as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $wasLinked = $property->contacts()->where('contacts.id', $cid)->exists();
                $property->contacts()->syncWithoutDetaching([$cid => ['role' => $defaultLinkRole]]);
                if (!$wasLinked) {
                    $linkedContact = \App\Models\Contact::find($cid);
                    if ($linkedContact) {
                        \App\Models\PropertySellerLink::ensureExists($property->id, $cid);
                        event(new \App\Events\Contact\ContactLinkedToProperty(
                            contact: $linkedContact,
                            property: $property,
                            role: $defaultLinkRole,
                            actorUserId: auth()->id(),
                        ));
                    }
                }
            }
        }

        // Create + link new contacts added during create (with duplicate detection)
        $dupService = app(\App\Services\ContactDuplicateService::class);
        $agencyId = auth()->user()->effectiveAgencyId() ?? 1;

        foreach ((array) $request->input('pending_new_contacts', []) as $nc) {
            if (empty($nc['first_name']) || empty($nc['last_name']) || empty($nc['phone'])) continue;
            $ncData = [
                'first_name' => substr($nc['first_name'], 0, 100),
                'last_name'  => substr($nc['last_name'],  0, 100),
                'phone'      => substr($nc['phone'],       0, 30),
                'email'      => !empty($nc['email']) ? substr($nc['email'], 0, 150) : null,
            ];
            // Auto-link if duplicate found (non-blocking in bulk create context)
            $existing = $dupService->findDuplicates($ncData, $agencyId)->first();
            if ($existing) {
                $wasLinked = $property->contacts()->where('contacts.id', $existing->id)->exists();
                $property->contacts()->syncWithoutDetaching([$existing->id => ['role' => $defaultLinkRole]]);
                $match = $dupService->identifyMatch($ncData, $existing, $agencyId);
                $dupService->logAttempt($agencyId, auth()->id(), 'auto_link', $match['field'], $match['value'], $existing->id, $ncData, 'auto_linked');
                if (!$wasLinked) {
                    \App\Models\PropertySellerLink::ensureExists($property->id, $existing->id);
                    event(new \App\Events\Contact\ContactLinkedToProperty(
                        contact: $existing,
                        property: $property,
                        role: $defaultLinkRole,
                        actorUserId: auth()->id(),
                    ));
                }
                continue;
            }
            $ncData['contact_type_id'] = !empty($nc['contact_type_id']) ? (int) $nc['contact_type_id'] : null;
            $ncData['created_by_user_id'] = auth()->id();

            // A.2.5 — optional SA ID number, mirroring PropertyContactController::createAndLink.
            // Normalise whitespace and only persist when it passes the SA-ID rule, so one
            // malformed entry in a bulk create never blocks the whole property save.
            $idNumber = !empty($nc['id_number']) ? preg_replace('/\s+/', '', (string) $nc['id_number']) : null;
            if ($idNumber && \Illuminate\Support\Facades\Validator::make(
                ['id_number' => $idNumber],
                ['id_number' => ['string', 'max:20', new \App\Rules\SouthAfricanIdNumber()]]
            )->passes()) {
                $ncData['id_number']             = $idNumber;
                $ncData['id_number_captured_at'] = now();
                $ncData['id_number_source']      = 'property_inline_create';
            }

            $contact = \App\Models\Contact::create($ncData);
            $property->contacts()->attach($contact->id, ['role' => $defaultLinkRole]);
            \App\Models\PropertySellerLink::ensureExists($property->id, $contact->id);
            event(new \App\Events\Contact\ContactLinkedToProperty(
                contact: $contact,
                property: $property,
                role: $defaultLinkRole,
                actorUserId: auth()->id(),
            ));
        }

            return $property;
        });

        // The create form falls back to an AJAX submit when it carries more
        // gallery images than PHP's max_file_uploads cap (default 20): the
        // property is created here without the gallery, then the gallery is
        // batch-uploaded to upload-images. Hand the client the new property's
        // id + show URL so it can run those batches and land on the property.
        if ($request->wantsJson()) {
            return response()->json([
                'ok'       => true,
                'property' => ['id' => $property->id],
                'redirect' => route('corex.properties.show', $property),
            ]);
        }

        return redirect()->route('corex.properties.show', $property)
            ->with('success', 'Property created.')
            ->with('tab', 'info');
    }

    public function edit(Property $property)
    {
        // Redirect edit to the show page's info tab
        return redirect()->route('corex.properties.show', $property);
    }

    public function update(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        if ($property->contacts()->count() === 0) {
            return back()
                ->withInput()
                ->withErrors(['contacts' => 'A contact must be linked to the property before saving.']);
        }

        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'excerpt'          => 'nullable|string|max:500',
            'description'      => 'nullable|string',
            'price'            => 'required|integer|min:0',
            'price_on_application' => 'nullable|boolean',
            'has_deposit'      => 'nullable|boolean',
            'lease_period'     => 'nullable|string|max:100',
            'price_per_day'    => 'nullable|numeric|min:0',
            'price_per_week'   => 'nullable|numeric|min:0',
            'price_per_year'   => 'nullable|numeric|min:0',
            'lease_type'       => 'nullable|string|max:100',
            'gross_price'      => 'nullable|numeric|min:0',
            'net_price'        => 'nullable|numeric|min:0',
            'yard_price'       => 'nullable|numeric|min:0',
            'primary_price_display' => 'nullable|string|in:monthly,daily,weekly,yearly',
            'rates_taxes'      => 'nullable|integer|min:0',
            'levy'             => 'nullable|integer|min:0',
            'special_levy'     => 'nullable|integer|min:0',
            'city'             => 'nullable|string|max:100',
            'suburb'           => 'required|string|max:100',
            'address'          => 'nullable|string|max:300',
            'region'           => 'nullable|string|max:100',
            'latitude'         => 'nullable|numeric|between:-90,90',
            'longitude'        => 'nullable|numeric|between:-180,180',
            'p24_province_id'  => 'nullable|integer|exists:p24_provinces,id',
            'p24_city_id'      => 'nullable|integer|exists:p24_cities,id',
            'p24_suburb_id'    => 'nullable|integer|exists:p24_suburbs,id',
            'beds'             => 'required|integer|min:0|max:20',
            'baths'            => 'required|integer|min:0|max:20',
            'half_baths'       => 'nullable|integer|min:0|max:20',
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'nullable|string|max:50',
            'category'         => 'nullable|string|max:100',
            // Build 3 — agency-isolated FK to property_setting_items
            // (group='condition_level'). The DB FK enforces id existence
            // + nullOnDelete; the controller need only validate type.
            'condition_level_id' => 'nullable|integer|exists:property_setting_items,id',
            'mandate_type'     => 'nullable|string|max:50',
            'listing_type'     => 'nullable|string|in:sale,rental',
            'status'           => 'nullable|string|max:100',
            'status_label'     => 'nullable|string|max:50',
            'features'         => 'nullable|array',
            'features.*'       => 'string|max:100',
            'spaces_json'      => 'nullable|string',
            'property_number'  => 'nullable|string|max:100',
            'complex_name'     => 'nullable|string|max:255',
            'unit_number'      => 'nullable|string|max:100',
            'floor_number'     => 'nullable|string|max:50',
            'unit_section_block' => 'nullable|string|max:255',
            'stand_number'     => 'nullable|string|max:100',
            'zone_type'        => 'nullable|string|max:100',
            'address_internal_note' => 'nullable|string|max:2000',
            'street_name'      => 'nullable|string|max:255',
            'street_number'    => 'nullable|string|max:50',
            'province'         => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:255',
            'rental_amount'    => 'nullable|numeric|min:0',
            'deposit_amount'   => 'nullable|numeric|min:0',
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'admin_fee'        => 'nullable|numeric|min:0',
            'marketing_fee'    => 'nullable|numeric|min:0',
            'listed_date'      => 'nullable|date',
            'expiry_date'      => 'nullable|date|after_or_equal:listed_date',
            'lease_start_date' => 'nullable|date',
            'lease_end_date'   => 'nullable|date',
            'branch_id'        => 'nullable|exists:branches,id',
            'agent_id'         => 'required|exists:users,id',
            'pp_second_agent_id' => 'nullable|exists:users,id',
            'pp_agent_image'           => 'nullable|image|max:1024',
            'pp_second_agent_image'    => 'nullable|image|max:1024',
            'youtube_video_id'   => 'nullable|string|max:500',
            'matterport_id'      => 'nullable|string|max:100',
            'virtual_tour_url'   => 'nullable|url|max:1000',
            'rental_price_type'  => 'nullable|string|max:50',
            'pp_hide_street_name'   => 'nullable|boolean',
            'pp_hide_street_number' => 'nullable|boolean',
            'pp_hide_complex_name'  => 'nullable|boolean',
            'pp_hide_unit_number'   => 'nullable|boolean',
            'p24_hide_address'      => 'nullable|boolean',
            'publish'          => 'nullable|boolean',
            'dawn_images'      => 'nullable|array',
            'dawn_images.*'    => 'image|max:512000',
            'noon_images'      => 'nullable|array',
            'noon_images.*'    => 'image|max:512000',
            'dusk_images'      => 'nullable|array',
            'dusk_images.*'    => 'image|max:512000',
            'gallery_images'   => 'nullable|array',
            'gallery_images.*' => 'image|max:512000',
        ]);

        // Agent images for portal syndication
        if ($request->hasFile('pp_agent_image')) {
            $data['pp_agent_image_path'] = $request->file('pp_agent_image')->store("properties/{$property->id}/agents", 'public');
        }
        if ($request->hasFile('pp_second_agent_image')) {
            $data['pp_second_agent_image_path'] = $request->file('pp_second_agent_image')->store("properties/{$property->id}/agents", 'public');
        }

        // Listing Agent ≡ Primary Agent invariant: when the primary agent changes,
        // clear the portal-feed photo snapshot so portal feeds + Ad Builder fall back to
        // the new agent's profile photo. Same for second agent.
        if (isset($data['agent_id']) && (int) $data['agent_id'] !== (int) $property->agent_id && !$request->hasFile('pp_agent_image')) {
            $data['pp_agent_image_path'] = null;
        }
        // Branch follows the primary agent — re-derive on every save so it stays in sync.
        // Preserve existing branch when the agent has no branch of their own.
        if (isset($data['agent_id'])) {
            $assignedAgent = User::find($data['agent_id']);
            $derivedBranchId = $assignedAgent ? ($assignedAgent->effectiveBranchId() ?? $assignedAgent->branch_id) : null;
            if ($derivedBranchId) {
                $data['branch_id'] = $derivedBranchId;
            }
        }
        if (array_key_exists('pp_second_agent_id', $data) && (int) ($data['pp_second_agent_id'] ?? 0) !== (int) ($property->pp_second_agent_id ?? 0) && !$request->hasFile('pp_second_agent_image')) {
            $data['pp_second_agent_image_path'] = null;
        }

        // Extract YouTube video ID from full URL if pasted
        if (!empty($data['youtube_video_id'])) {
            $data['youtube_video_id'] = self::extractYoutubeId($data['youtube_video_id']);
        }

        // Checkboxes that aren't checked don't submit — ensure they're explicitly set to false
        $data['pp_hide_street_name']   = $request->boolean('pp_hide_street_name');
        $data['pp_hide_street_number'] = $request->boolean('pp_hide_street_number');
        $data['pp_hide_complex_name']  = $request->boolean('pp_hide_complex_name');
        $data['pp_hide_unit_number']   = $request->boolean('pp_hide_unit_number');
        // P24 address-display flag — independent of the PP flags above. Unchecked
        // checkboxes don't submit, so coerce explicitly on every save.
        $data['p24_hide_address']      = $request->boolean('p24_hide_address');

        $data = $this->processSpacesJson($data);
        // P24 link is OPTIONAL on edit. A legacy/imported property whose suburb
        // isn't on Property24 (or simply isn't linked yet) must still be
        // saveable — forcing a re-pick on every save trapped every such record.
        // When a suburb IS picked, the chain is still verified + canonicalised;
        // when it isn't, the free-text location is kept and p24_suburb_mismatch
        // is flagged so P24 syndication (not the save) is what's gated.
        $data = $this->applyP24Location($data, false);

        if (! empty($data['publish']) && ! $property->isPublished()) {
            $data['published_at'] = now();
            // Clean status model: on-market status is 'active' (stock type lives on
            // listing_type). Promote only a draft/empty placeholder to active; keep
            // the existing on-market base status (and sub-label) intact.
            $cur = $data['status'] ?? $property->status ?? '';
            if ($cur === '' || $cur === 'draft') {
                $data['status'] = 'active';
            }
        }
        unset($data['publish']);
        // Sub-label banner is meaningful only on an on-market (active) listing.
        if (array_key_exists('status', $data) && $data['status'] !== null
            && $data['status'] !== '' && $data['status'] !== 'active') {
            $data['status_label'] = null;
        }

        // `status` is NOT NULL. An empty status field arrives as null via
        // ConvertEmptyStringsToNull — never let that overwrite the existing
        // status (this is what broke saving / image uploads when the form's
        // status field came through empty).
        if (array_key_exists('status', $data) && ($data['status'] === null || $data['status'] === '')) {
            unset($data['status']);
        }

        // Append new uploads to existing arrays
        $newDawn    = $this->storeImages($request, 'dawn_images',    $property->id);
        $newNoon    = $this->storeImages($request, 'noon_images',    $property->id);
        $newDusk    = $this->storeImages($request, 'dusk_images',    $property->id);
        $newGallery = $this->storeImages($request, 'gallery_images', $property->id);

        if ($newDawn)    $data['dawn_images_json']    = array_merge($property->dawn_images_json    ?? [], $newDawn);
        if ($newNoon)    $data['noon_images_json']    = array_merge($property->noon_images_json    ?? [], $newNoon);
        if ($newDusk)    $data['dusk_images_json']    = array_merge($property->dusk_images_json    ?? [], $newDusk);
        if ($newGallery) {
            $data['gallery_images_json'] = array_merge($property->gallery_images_json ?? [], $newGallery);

            // Auto-tag new images with category if provided (mobile app support)
            $uploadCategory = $request->input('image_category');
            if ($uploadCategory) {
                $cats = $property->gallery_categories_json ?? ['categories' => [], 'unsorted' => []];
                $found = false;
                foreach ($cats['categories'] as &$cat) {
                    if ($cat['name'] === $uploadCategory) {
                        $cat['images'] = array_merge($cat['images'] ?? [], $newGallery);
                        $found = true;
                        break;
                    }
                }
                unset($cat);
                if (!$found) {
                    $cats['categories'][] = ['name' => $uploadCategory, 'images' => $newGallery];
                }
                $data['gallery_categories_json'] = $cats;
            }
        }

        $previousP24SuburbId = $property->p24_suburb_id;
        $property->update($data);
        if (isset($data['p24_suburb_id'])
            && (int) $data['p24_suburb_id'] > 0
            && (int) $previousP24SuburbId !== (int) $data['p24_suburb_id']) {
            event(new \App\Events\Property\PropertySuburbLinked(
                property: $property->fresh(),
                previousP24SuburbId: $previousP24SuburbId ? (int) $previousP24SuburbId : null,
                newP24SuburbId: (int) $data['p24_suburb_id'],
                actorUserId: auth()->id(),
            ));
        }
        // Force-touch updated_at even when no fillable attribute changed (e.g. only photos uploaded),
        // so the Modified column always reflects the latest save action.
        if (! $property->wasChanged()) {
            $property->touch();
        }

        // The agent opened/actioned the AI photo-suggestions modal during this
        // edit — stamp the analyses reviewed so the modal won't re-appear. The
        // accepted spaces/features themselves rode in via spaces_json above.
        if ($request->boolean('ai_review')) {
            app(\App\Services\AI\PropertyAiSuggestionService::class)->markReviewed($property);
        }

        return redirect()->route('corex.properties.show', $property)
            ->with('success', 'Property updated.')
            ->with('tab', 'info');
    }

    public function destroy(Property $property)
    {
        $this->authorizeProperty($property);
        $property->delete();
        return redirect()->route('corex.properties.index')
            ->with('success', 'Property listing removed.');
    }

    public function duplicate(Property $property)
    {
        $this->authorizeProperty($property);

        $clone = $property->replicate([
            'external_id', 'published_at', 'p24_ref', 'p24_syndication_enabled',
            'p24_syndication_status', 'p24_last_submitted_at', 'p24_activated_at',
            'p24_last_error', 'p24_images_last_synced_at', 'p24_listing_last_synced_at',
            'pp_ref', 'pp_syndication_enabled', 'pp_syndication_status',
            'pp_last_submitted_at', 'pp_activated_at', 'pp_last_error',
            'pp_listing_feed_ref', 'pp_exclusive_days', 'pp_delay_until',
            'pp_images_last_synced_at', 'pp_listing_last_synced_at',
        ]);

        $clone->title  = ($property->title ?? 'Property') . ' (Copy)';
        $clone->status = 'draft';
        $clone->price  = null;
        $clone->unit_number = null;
        $clone->published_at = null;
        $clone->p24_syndication_enabled = false;
        $clone->pp_syndication_enabled = false;
        $clone->save();

        // Copy contact links
        foreach ($property->contacts as $contact) {
            $clone->contacts()->attach($contact->id, ['role' => $contact->pivot->role]);
        }

        return redirect()->route('corex.properties.show', $clone)
            ->with('success', 'Property duplicated. Update the details and save.');
    }

    public function publishToggle(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $action = $request->input('action', 'toggle');
        // Gate: enforce marketing readiness when PUBLISHING (not when unpublishing)
        if ($action === 'publish' || $action === 'refresh' || ($action === 'toggle' && ! $property->published_at)) {
            $this->enforceMarketingReadiness($property);
            $missing = [];
            if (! $property->agent)             $missing[] = 'Listing agent';
            elseif (empty($property->agent->phone)) $missing[] = 'Agent phone number';
            elseif (empty($property->agent->email)) $missing[] = 'Agent email';
            if (empty($property->title))   $missing[] = 'Title';
            if (empty($property->price))   $missing[] = 'Price';
            if (empty($property->status))  $missing[] = 'Status';
            if (empty($property->suburb))  $missing[] = 'Suburb';
            if ($missing) {
                $msg = 'Cannot publish to HFC Premium — missing: ' . implode(', ', $missing);
                if ($request->wantsJson() || $request->ajax()) {
                    return response()->json(['error' => $msg, 'missing' => $missing], 422);
                }
                return back()->with('error', $msg);
            }
        }
        if ($action === 'publish' || $action === 'refresh') {
            $property->published_at = now();
            $msg = $action === 'refresh' ? 'Listing refreshed on HFC Premium.' : 'Published to HFC Premium.';
        } elseif ($action === 'unpublish') {
            $property->published_at = null;
            $msg = 'Unpublished from HFC Premium.';
        } else {
            $property->published_at = $property->published_at ? null : now();
            $msg = $property->published_at ? 'Published to HFC Premium.' : 'Unpublished from HFC Premium.';
        }
        $property->save();

        return back()->with('success', $msg);
    }

    public function uploadImages(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate([
            'group'           => 'nullable|in:gallery_images,dawn_images,noon_images,dusk_images',
            'gallery_images'  => 'nullable|array',
            'gallery_images.*'=> 'image|max:512000',
            'dawn_images'     => 'nullable|array',
            'dawn_images.*'   => 'image|max:512000',
            'noon_images'     => 'nullable|array',
            'noon_images.*'   => 'image|max:512000',
            'dusk_images'     => 'nullable|array',
            'dusk_images.*'   => 'image|max:512000',
        ]);

        $groups = ['gallery_images', 'dawn_images', 'noon_images', 'dusk_images'];
        $added  = 0;
        $updates = [];

        foreach ($groups as $field) {
            if (!$request->hasFile($field)) {
                continue;
            }
            $existing = $property->{$field . '_json'} ?? [];
            $new      = $this->storeImages($request, $field, $property->id);
            if (!empty($new)) {
                $updates[$field . '_json'] = array_values(array_merge($existing, $new));
                $added += count($new);
            }
        }

        if (!empty($updates)) {
            $property->update($updates);
        }

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'added' => $added]);
        }

        return back()
            ->with('success', $added > 0 ? "Uploaded {$added} image(s)." : 'No images uploaded.')
            ->with('tab', 'gallery');
    }

    public function deleteImage(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate([
            'group' => 'required|in:gallery_images_json,dawn_images_json,noon_images_json,dusk_images_json',
            'index' => 'required|integer|min:0',
        ]);

        $group  = $request->group;
        $index  = (int) $request->index;
        $images = $property->$group ?? [];

        if (isset($images[$index])) {
            // Delete the file from storage
            $url  = $images[$index];
            $path = str_replace('/storage/', '', parse_url($url, PHP_URL_PATH));
            Storage::disk('public')->delete($path);

            array_splice($images, $index, 1);
            $property->update([$group => $images]);
        }

        return back()->with('success', 'Image deleted.')->with('tab', 'gallery');
    }

    // ── Rental inspection galleries ─────────────────────────────────────────
    // Only meaningful for rental listings. Data lives in properties.rental_images_json,
    // normalised through Property::rentalImagesStructure(). Files are stored exactly
    // like marketing gallery images (storeImages → storage/public/properties/{id}/).
    // Spec: .ai/specs/rental-images.md

    /**
     * Append uploaded images to one rental section (in_inspection, out_inspection,
     * or a custom section identified by custom_id). Multipart. Files arrive under
     * the `images` input. Returns the appended URLs as JSON.
     */
    public function uploadRentalImages(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate([
            'section'   => 'required|in:in_inspection,out_inspection,custom',
            'custom_id' => 'nullable|string|required_if:section,custom',
            'images'    => 'required|array',
            'images.*'  => 'image|max:512000',
        ]);

        $structure = $property->rentalImagesStructure();
        $new       = $this->storeImages($request, 'images', $property->id);

        if (!empty($new)) {
            if ($request->section === 'custom') {
                $found = false;
                foreach ($structure['custom'] as $i => $sec) {
                    if ($sec['id'] === $request->custom_id) {
                        $structure['custom'][$i]['images'] = array_values(array_merge($sec['images'], $new));
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    abort(404, 'Section not found.');
                }
            } else {
                $structure[$request->section]['images'] = array_values(
                    array_merge($structure[$request->section]['images'], $new)
                );
            }

            $property->update(['rental_images_json' => $structure]);
        }

        return response()->json(['ok' => true, 'urls' => $new, 'rental_images' => $structure]);
    }

    /**
     * Persist the metadata layer of the rental galleries: per-section dates,
     * custom-section adds (server mints the id) and renames. The structure is
     * rebuilt server-side from the normalised current state + the posted intent
     * so a client can never inject arbitrary keys or overwrite stored images.
     */
    public function saveRentalImagesMeta(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'action'           => 'required|in:set_date,add_section,rename_section',
            'section'          => 'required_if:action,set_date|in:in_inspection,out_inspection,custom',
            // nullable first: the ConvertEmptyStringsToNull middleware turns an
            // empty custom_id ('' for the fixed in/out sections) into null, which
            // the string rule would otherwise reject. required_if still forces a
            // value when the section is custom / the action is a rename.
            'custom_id'        => 'nullable|string|required_if:section,custom|required_if:action,rename_section',
            'date'             => 'nullable|date',
            'name'             => 'required_if:action,add_section,rename_section|string|max:120',
        ]);

        $structure = $property->rentalImagesStructure();

        if ($data['action'] === 'set_date') {
            $date = $data['date'] ?? null; // already validated as a date or null
            if (($data['section'] ?? null) === 'custom') {
                foreach ($structure['custom'] as $i => $sec) {
                    if ($sec['id'] === ($data['custom_id'] ?? null)) {
                        $structure['custom'][$i]['date'] = $date;
                        break;
                    }
                }
            } else {
                $structure[$data['section']]['date'] = $date;
            }
        } elseif ($data['action'] === 'add_section') {
            // Mint a collision-free short id against the existing custom sections.
            do {
                $id = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
            } while (collect($structure['custom'])->contains('id', $id));

            $structure['custom'][] = [
                'id'     => $id,
                'name'   => trim($data['name']),
                'date'   => null,
                'images' => [],
            ];
        } elseif ($data['action'] === 'rename_section') {
            foreach ($structure['custom'] as $i => $sec) {
                if ($sec['id'] === $data['custom_id']) {
                    $structure['custom'][$i]['name'] = trim($data['name']);
                    break;
                }
            }
        }

        $property->update(['rental_images_json' => $structure]);

        return response()->json(['ok' => true, 'rental_images' => $structure]);
    }

    /**
     * Remove one image from a rental section and delete its file from disk.
     * Mirrors deleteImage() for the marketing gallery — JSON array entries are
     * not Eloquent models, so this follows the established image-removal pattern.
     */
    public function deleteRentalImage(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'section'   => 'required|in:in_inspection,out_inspection,custom',
            'custom_id' => 'nullable|string|required_if:section,custom',
            'index'     => 'required|integer|min:0',
        ]);

        $structure = $property->rentalImagesStructure();
        $index     = (int) $data['index'];

        $removeAt = function (array $images, int $idx): array {
            if (isset($images[$idx])) {
                $path = str_replace('/storage/', '', parse_url($images[$idx], PHP_URL_PATH));
                Storage::disk('public')->delete($path);
                array_splice($images, $idx, 1);
            }
            return array_values($images);
        };

        if ($data['section'] === 'custom') {
            foreach ($structure['custom'] as $i => $sec) {
                if ($sec['id'] === $data['custom_id']) {
                    $structure['custom'][$i]['images'] = $removeAt($sec['images'], $index);
                    break;
                }
            }
        } else {
            $structure[$data['section']]['images'] = $removeAt($structure[$data['section']]['images'], $index);
        }

        $property->update(['rental_images_json' => $structure]);

        return response()->json(['ok' => true, 'rental_images' => $structure]);
    }

    public function reorderImages(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        // Smart gallery saves both categories and flat list
        if ($request->has('gallery_categories_json')) {
            $updates = [
                'gallery_categories_json' => $request->input('gallery_categories_json'),
                'gallery_images_json'     => $request->input('gallery_images_json', []),
            ];

            // Persist the custom-tag registry so a custom tag survives even when
            // no photo is filed under it yet (an empty tag has no category in
            // gallery_categories_json to derive it from). The client sends the
            // full ordered tag library; we keep only the tags that are NOT
            // room-derived — storing derived names would strand them if a space
            // is later removed. Without this the registry stayed NULL and custom
            // tags leaned entirely on filed photos to survive (property 6060).
            if ($request->has('gallery_available_tags')) {
                $available = array_values(array_filter(
                    (array) $request->input('gallery_available_tags', []),
                    'is_string'
                ));
                $derivedLower = array_map('strtolower', $property->derivedGalleryTags());
                $custom = array_values(array_filter(
                    array_map('trim', $available),
                    fn ($t) => $t !== '' && !in_array(strtolower($t), $derivedLower, true)
                ));
                $updates['gallery_custom_tags'] = $custom;
            }

            $property->update($updates);

            return response()->json(['ok' => true]);
        }

        // Legacy reorder (flat list by index)
        $request->validate([
            'group'  => 'required|in:gallery_images_json,dawn_images_json,noon_images_json,dusk_images_json',
            'order'  => 'required|array',
            'order.*'=> 'integer|min:0',
        ]);

        $group     = $request->group;
        $oldImages = $property->$group ?? [];
        $newImages = [];

        foreach ($request->order as $oldIndex) {
            if (isset($oldImages[(int) $oldIndex])) {
                $newImages[] = $oldImages[(int) $oldIndex];
            }
        }

        $property->update([$group => $newImages]);

        return response()->json(['ok' => true]);
    }

    /**
     * Rotate a single gallery image 90°/180°. The rotated file is written under
     * a NEW name (PropertyImageRotator) and we swap the old URL → new URL across
     * every image field that may reference it, so the corrected orientation
     * propagates to the gallery, public site, portals, presentations and exports
     * with zero stale-cache risk. Spec: .ai/specs/gallery-image-rotation.md
     */
    public function rotateImage(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'image_url' => 'required|string',
            'degrees'   => 'required|integer|in:90,-90,180',
        ]);

        // Drop any cache-bust query before matching against stored URLs.
        $oldUrl = strtok($data['image_url'], '?');

        try {
            $newUrl = (new \App\Services\Images\PropertyImageRotator())
                ->rotate($property, $oldUrl, (int) $data['degrees']);
        } catch (\InvalidArgumentException $e) {
            \Log::warning('Image rotation rejected', [
                'property_id' => $property->id, 'image_url' => $oldUrl, 'reason' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        } catch (\RuntimeException $e) {
            \Log::error('Image rotation failed', [
                'property_id' => $property->id, 'image_url' => $oldUrl, 'reason' => $e->getMessage(),
            ]);
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 500);
        }

        $this->swapImageUrl($property, $oldUrl, $newUrl);

        return response()->json(['ok' => true, 'url' => $newUrl]);
    }

    /**
     * Replace an image URL everywhere it can appear on a property: the four
     * image lists and the URL-keyed gallery category structure. Persisted in a
     * single update so the listing never references the deleted original.
     */
    private function swapImageUrl(Property $property, string $old, string $new): void
    {
        $updates = [];

        foreach (['gallery_images_json', 'dawn_images_json', 'noon_images_json', 'dusk_images_json'] as $field) {
            $arr = $property->{$field};
            if (! is_array($arr)) {
                continue;
            }
            $changed = false;
            foreach ($arr as $i => $url) {
                if ($url === $old) {
                    $arr[$i] = $new;
                    $changed = true;
                }
            }
            if ($changed) {
                $updates[$field] = array_values($arr);
            }
        }

        // gallery_categories_json: { categories: [{name, images:[url...]}], unsorted: [url...] }
        $cats = $property->gallery_categories_json;
        if (is_array($cats)) {
            $catChanged = false;
            if (! empty($cats['categories']) && is_array($cats['categories'])) {
                foreach ($cats['categories'] as $ci => $cat) {
                    if (empty($cat['images']) || ! is_array($cat['images'])) {
                        continue;
                    }
                    foreach ($cat['images'] as $ii => $url) {
                        if ($url === $old) {
                            $cats['categories'][$ci]['images'][$ii] = $new;
                            $catChanged = true;
                        }
                    }
                }
            }
            if (! empty($cats['unsorted']) && is_array($cats['unsorted'])) {
                foreach ($cats['unsorted'] as $ui => $url) {
                    if ($url === $old) {
                        $cats['unsorted'][$ui] = $new;
                        $catChanged = true;
                    }
                }
            }
            if ($catChanged) {
                $updates['gallery_categories_json'] = $cats;
            }
        }

        if ($updates) {
            $property->update($updates);
        }
    }

    public function ad(Property $property)
    {
        $this->authorizeProperty($property);
        $property->load(['agent', 'branch']);

        /** @var User $user */
        $user = auth()->user();

        // Every custom template built in THIS agency is visible to the whole
        // agency (AgencyScope keeps other agencies' templates out). No user_id
        // filter and no `is_global` OR-clause — the latter leaked global
        // templates across agencies via operator precedence. Spec ad-manager.md §5.
        $savedTemplates = PropertyAdTemplate::orderByDesc('updated_at')
            ->get(['id', 'user_id', 'name', 'layout_json', 'updated_at'])
            ->map(function (PropertyAdTemplate $tpl) use ($user) {
                // Per-template edit/delete right surfaced to the picker UI.
                $tpl->setAttribute('can_manage', $tpl->canBeManagedBy($user));
                return $tpl;
            });

        $canManageTemplates = $user->hasPermission('access_properties');

        // ── Co-listing agent option (ad-manager.md §"Agent identity") ──────────
        // The ad shows the listing agent. ONLY when the listing is co-listed
        // (pp_second_agent_id) does the generator offer a choice: listing agent,
        // co-agent, or both. There is no general agent picker — a property's ad
        // shows the people who actually work it. The swap is client-side (prebuilt
        // templates are server-rendered with the listing agent; JS re-points the
        // agent text nodes when the user changes the choice).
        $listingAgentCard = Property::agentAdCard($property->agent);
        $coAgentCard = ($property->pp_second_agent_id && (int) $property->pp_second_agent_id !== (int) $property->agent_id && $property->secondAgent)
            ? Property::agentAdCard($property->secondAgent)
            : null;

        // Printable Brochure (always-first / always-A4 template) — preview data
        // for its picker card. embed:false → plain image URLs (fast browser load);
        // the real A4 PDF (embed:true) is produced by the brochure() route.
        $brochureData = app(\App\Services\Properties\PropertyBrochureService::class)->data($property, embed: false);

        return view('corex.properties.ad', compact(
            'property', 'savedTemplates', 'canManageTemplates', 'brochureData',
            'listingAgentCard', 'coAgentCard',
        ));
    }

    /**
     * Printable Brochure — stream the property's A4 PDF data sheet.
     * The always-first / always-A4 entry in the Ad Manager template picker.
     * Spec: .ai/specs/ad-manager.md §"Printable Brochure".
     */
    public function brochure(Property $property, \App\Services\Properties\PropertyBrochureService $service)
    {
        $this->authorizeProperty($property);

        // Agent identity (ad-manager.md §"Agent identity"): ?ad_agent points the
        // footer at another in-scope agent; ?co=1 co-brands with the co-listing
        // agent. AgencyScope on User::find keeps the override inside the agency;
        // an out-of-agency or unknown id silently falls back to the listing agent.
        $primary = null;
        if ($id = (int) request('ad_agent')) {
            $primary = User::find($id);
        }
        $secondary = request()->boolean('co') && $property->pp_second_agent_id
            ? User::find((int) $property->pp_second_agent_id)
            : null;

        $pdf = $service->pdf($property, $primary, $secondary);

        // ?dl=1 forces a download; default opens inline so the picker card can
        // preview it in a new tab.
        return request()->boolean('dl')
            ? $pdf->download($service->filename($property))
            : $pdf->stream($service->filename($property));
    }

    public function livePreview(Property $property, \Illuminate\Http\Request $request)
    {
        // Public listing preview — gate by marketing readiness
        $svc = app(\App\Services\Compliance\MarketingReadinessService::class);
        if (!$svc->isMarketable($property)) {
            abort(404);
        }
        $property->load(['agent', 'branch', 'agency']);

        /** @var User|null $authUser */
        $authUser = auth()->user();

        $agentChoice  = $request->query('agent', 'listing');
        $displayAgent = ($agentChoice === 'me' && $authUser)
            ? $authUser
            : ($property->agent ?? $authUser);

        return view('corex.properties.live-preview', compact('property', 'displayAgent', 'agentChoice'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function goLive(Request $request, Property $property)
    {
        $user = $request->user();

        // Permission: listing agent, branch_manager, admin, super_admin
        $isListingAgent = (int) $property->agent_id === (int) $user->id;
        $isPrivileged = in_array($user->role ?? $user->effectiveRole(), ['super_admin', 'admin', 'owner', 'branch_manager']);
        if (!$isListingAgent && !$isPrivileged) {
            abort(403, 'Only the listing agent or a manager can go live.');
        }

        // Already live — return success idempotently
        if ($property->compliance_snapshot_at !== null) {
            return response()->json([
                'ok' => true,
                'snapshot_at' => $property->compliance_snapshot_at->toIso8601String(),
                'message' => 'Property is already live.',
            ]);
        }

        $svc = app(\App\Services\Compliance\MarketingReadinessService::class);

        try {
            $svc->snapshotCompliance($property, $user);
        } catch (\App\Services\Compliance\MarketingBlockedException $e) {
            return response()->json([
                'ok' => false,
                'message' => 'Property does not meet marketing readiness requirements.',
                'blocked_by' => $e->getReport()->blockedBy,
                'checklist' => $e->getReport()->checklist,
            ], 422);
        }

        $property->refresh();

        return response()->json([
            'ok' => true,
            'snapshot_at' => $property->compliance_snapshot_at->toIso8601String(),
            'message' => 'Property is now live and ready for marketing.',
        ]);
    }

    private function processSpacesJson(array $data): array
    {
        $rawJson = $data['spaces_json'] ?? null;
        unset($data['features'], $data['spaces_json']);

        if (!empty($rawJson)) {
            $decoded = json_decode($rawJson, true);
            if ($decoded) {
                $data['spaces_json'] = $decoded;

                // Build flat features_json for backward compat (overview tab)
                $flat = [];
                foreach ($decoded['spaces'] ?? [] as $sp) {
                    foreach ($sp['featuresAll'] ?? [] as $f) { $flat[] = $f; }
                    foreach ($sp['units'] ?? [] as $u) {
                        foreach ($u['features'] ?? [] as $f) { $flat[] = $f; }
                    }
                }
                foreach ($decoded['features'] ?? [] as $catArr) {
                    if (is_array($catArr)) {
                        foreach ($catArr as $f) { $flat[] = $f; }
                    }
                }
                $data['features_json'] = array_values(array_unique(array_filter($flat)));

                // Sync beds/baths from spaces so DB columns stay correct
                foreach ($decoded['spaces'] ?? [] as $sp) {
                    if ($sp['type'] === 'Bedroom')  { $data['beds']  = (int) ($sp['count'] ?? 0); }
                    if ($sp['type'] === 'Bathroom') { $data['baths'] = (int) ($sp['count'] ?? 0); }
                }
            }
        } else {
            $data['spaces_json'] = null;
        }

        return $data;
    }

    // Marketing-gallery + rental-inspection image storage delegates to the
    // canonical PropertyImageStorer so the web and mobile channels share one
    // store-location / sizing / encoding implementation (no drift).
    private function storeImages(Request $request, string $field, int $propertyId): array
    {
        return app(\App\Services\Images\PropertyImageStorer::class)->storeMany(
            $request->hasFile($field) ? (array) $request->file($field) : [],
            $propertyId
        );
    }

    private function agentList(?Property $property = null): \Illuminate\Support\Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        $assignedIds = array_filter([
            $property?->agent_id,
            $property?->pp_second_agent_id,
        ]);

        $query = User::agencyMembers()->orderBy('name')->where(function ($q) use ($scope, $user, $assignedIds) {
            $q->where('is_active', 1);

            if ($scope === 'branch') {
                $branchId = $user->effectiveBranchId();
                if ($branchId) {
                    $q->where('branch_id', $branchId);
                }
            } elseif ($scope !== 'all') {
                $q->where('id', $user->id);
            }

            if (!empty($assignedIds)) {
                $q->orWhereIn('id', $assignedIds);
            }
        });

        return $query->get(['id', 'name', 'email']);
    }

    /**
     * Resolve property GPS from the full structured address — used by the
     * property Map strip on show.blade.php to replace the suburb-only
     * Nominatim call with a building-level lookup via AddressResolverService.
     *
     * Why: the legacy frontend geocodeSuburb() in show.blade.php sent only
     * "suburb, city, state" to Nominatim, which returned the suburb centroid.
     * That centroid was then persisted as properties.latitude/longitude,
     * locking every property to a pin 3–4 km off the actual building.
     * The backend resolver uses street_number + street_name when present
     * (Google → Nominatim → KZN bbox clamp) and degrades to suburb_centroid
     * with that explicit source label only when no street parts are known.
     *
     * Two modes:
     *   - Payload mode: client sends street_number / street_name / suburb /
     *     town in the body → resolve from those without persisting (user is
     *     editing the form; submit will persist via PropertyObserver).
     *   - Saved-record mode (no payload): resolve from the property's saved
     *     address columns, persist via PropertyGeoBackfillService(force=true).
     */
    public function geocode(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $payload = $request->validate([
            'street_number' => 'sometimes|nullable|string|max:50',
            'street_name'   => 'sometimes|nullable|string|max:200',
            'suburb'        => 'sometimes|nullable|string|max:200',
            'town'          => 'sometimes|nullable|string|max:200',
            'force'         => 'sometimes|boolean',
        ]);

        $hasOverride = $request->hasAny(['street_number', 'street_name', 'suburb', 'town']);

        if ($hasOverride) {
            // Payload mode — resolve without persisting.
            $resolver = new \App\Services\Geocoding\AddressResolverService();
            $structured = trim(implode(' ', array_filter([
                $payload['street_number'] ?? null,
                $payload['street_name']   ?? null,
            ])));
            $address = $structured !== '' ? $structured : (string) ($property->address ?? '');
            $suburb  = $payload['suburb'] ?? $property->suburb;
            $town    = $payload['town']   ?? $property->town;

            try {
                $result = $resolver->resolve(
                    $address,
                    $suburb,
                    $town,
                    context: 'property:' . $property->id . ':inflight',
                );
            } catch (\Throwable $e) {
                // External geocoder (Google → Nominatim) failed/timed out — return a
                // structured error instead of a 500 so the form can degrade gracefully.
                \Illuminate\Support\Facades\Log::warning('Geocode resolve failed', [
                    'property_id' => $property->id, 'error' => $e->getMessage(),
                ]);
                return response()->json([
                    'ok'      => false,
                    'message' => 'Could not resolve the address right now. Please try again.',
                ], 502);
            }

            $source = $result->source;
            $isApprox = in_array((string) $source, [
                'suburb_centroid', 'unresolved', 'nominatim_suburb',
            ], true);

            return response()->json([
                'ok'             => $result->hasGps(),
                'latitude'       => $result->hasGps() ? (float) $result->latitude : null,
                'longitude'      => $result->hasGps() ? (float) $result->longitude : null,
                'source'         => $source,
                'confidence'     => $result->confidence,
                'resolved_at'    => null,
                'is_approximate' => $isApprox,
                'persisted'      => false,
            ]);
        }

        // Saved-record mode — persist.
        $force = (bool) ($payload['force'] ?? false);
        $service = new \App\Services\Geocoding\PropertyGeoBackfillService();
        $property->refresh();
        try {
            $result = $service->backfillProperty($property, batchId: null, force: $force);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Geocode backfill failed', [
                'property_id' => $property->id, 'error' => $e->getMessage(),
            ]);
            return response()->json([
                'ok'      => false,
                'message' => 'Could not resolve the address right now. Please try again.',
            ], 502);
        }
        $property->refresh();

        $isApproximate = in_array((string) $property->geo_source, [
            'suburb_centroid', 'unresolved', 'nominatim_suburb',
        ], true);

        return response()->json([
            'ok'              => (bool) $result['lat_lng_resolved'],
            'latitude'        => $property->latitude !== null ? (float) $property->latitude : null,
            'longitude'       => $property->longitude !== null ? (float) $property->longitude : null,
            'source'          => $property->geo_source,
            'confidence'      => $property->geo_confidence,
            'resolved_at'     => $property->geo_resolved_at?->toIso8601String(),
            'is_approximate'  => $isApproximate,
            'persisted'       => true,
        ]);
    }

    // authorizeProperty() now lives in the AuthorizesPropertyAccess trait.

    // ── Restore soft-deleted ──

    public function restore($id)
    {
        abort_unless(auth()->user()->hasPermission('properties.edit'), 403);
        $record = Property::onlyTrashed()->findOrFail($id);
        $record->restore();
        return redirect()->back()->with('success', 'Record restored.');
    }

    /**
     * Extract the 11-char YouTube video ID from a full URL or return as-is if already an ID.
     */
    private static function extractYoutubeId(string $input): string
    {
        $input = trim($input);

        // Already an 11-char ID
        if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $input)) {
            return $input;
        }

        // youtube.com/watch?v=ID, /embed/ID, /shorts/ID, /live/ID, /v/ID, youtu.be/ID
        if (preg_match('/(?:youtube\.com\/(?:watch\?\S*?v=|embed\/|shorts\/|live\/|v\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $input, $m)) {
            return $m[1];
        }

        // Not a recognisable YouTube id/URL — return empty rather than a
        // garbage truncation that would silently pass PP's 11-char check.
        return '';
    }
}
