<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use App\Models\DocumentType;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use App\Services\Syndication\Property24\Property24ListingMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    use \App\Http\Controllers\Concerns\EnforcesMarketingReadiness;
    use \App\Http\Concerns\AppliesP24Location;

    public function index(Request $request)
    {
        /** @var User $user */
        $user           = auth()->user();
        $dataScope      = PermissionService::getDataScope($user, 'properties');
        $viewScope      = $request->query('scope', 'my');   // 'my' | 'branch'
        $status         = $request->query('status', '');    // '' | draft | active | sold | withdrawn
        $search         = trim($request->query('search', ''));
        $filterAgentId  = $request->query('agent_id', '');  // admin/bm: view a specific agent's listings

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
        $sort           = $request->query('sort', 'newest');     // newest|oldest|price_asc|price_desc|title

        $query = Property::with(['agent', 'branch']);

        $canPickAgent = in_array($dataScope, ['all', 'branch']);

        // Agent filter with session persistence (mirrors ContactController).
        // Defaults to self on fresh load UNLESS a compliance filter is active
        // (card click-through shows full scope).
        if ($canPickAgent) {
            if ($request->has('agent_id')) {
                // Explicit selection ("All" or another agent) — this browse only.
                $filterAgentId = $request->query('agent_id', '');
            } elseif ($request->query('filter') === 'marketing_pending') {
                // Compliance card click-through shows full scope.
                $filterAgentId = '';
            } else {
                // Always default to the current user's own listings on a fresh
                // visit. Not persisted across visits.
                $filterAgentId = (string) $user->id;
            }
        }

        // Scope
        if ($canPickAgent && $filterAgentId !== '') {
            // Admin/BM viewing a specific agent
            $query->where('agent_id', (int) $filterAgentId);
        } elseif ($dataScope === 'all') {
            // Admin sees everything — no scope restriction
        } elseif ($dataScope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) $query->where('branch_id', $branchId);
        } else {
            // Agent: 'my' = own listings only; 'branch' = all branch listings
            if ($viewScope === 'branch' && $user->branch_id) {
                $query->where('branch_id', $user->branch_id);
            } else {
                $query->where('agent_id', $user->id);
            }
        }

        if ($status === 'published') {
            $query->whereNotNull('published_at');
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
            $query->whereNull('compliance_snapshot_at')->whereNotIn('status', ['sold', 'withdrawn', 'draft']);
        }

        if ($search !== '') {
            $query->searchAddress($search);
        }

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
        if (isset($sortableColumns[$sort])) {
            $query->orderBy($sortableColumns[$sort], $dir);
        } else {
            $query->orderByDesc('created_at');
            $sort = 'created_at';
            $dir = 'desc';
        }

        $properties = $query->get();

        // Compute marketing status per property (batch-friendly for Phase 1)
        $readinessSvc = app(\App\Services\Compliance\MarketingReadinessService::class);
        foreach ($properties as $p) {
            if ($p->compliance_snapshot_at !== null) {
                $p->marketing_status = 'live';
                $p->marketing_status_detail = 'Live since ' . $p->compliance_snapshot_at->format('j M Y');
            } elseif (in_array($p->status, ['sold', 'withdrawn', 'draft'])) {
                $p->marketing_status = 'n/a';
                $p->marketing_status_detail = '';
            } else {
                $report = $readinessSvc->statusFor($p);
                $p->marketing_status = $report->ready ? 'ready' : 'blocked';
                $p->marketing_status_detail = $report->ready ? 'All gates passed' : implode(', ', array_map(fn ($b) => \Illuminate\Support\Str::limit($b, 30), $report->blockedBy));
            }
        }

        // Sort by marketing_status (derived — PHP sort)
        if ($sort === 'marketing_status') {
            $properties = $properties->sortBy('marketing_status', SORT_REGULAR, $dir === 'desc')->values();
        }

        // Stats for the header KPIs
        $stats = [
            'total'    => $properties->count(),
            'active'   => $properties->where('status', 'active')->count(),
            'draft'    => $properties->where('status', 'draft')->count(),
            'sold'     => $properties->where('status', 'sold')->count(),
            'synced'   => $properties->whereNotNull('published_at')->count(),
        ];

        // Agent list for the picker (admin/bm only)
        $agentList = $canPickAgent ? $this->agentList()->values() : collect();

        // Resolve the selected agent's name for the button label
        $selectedAgent = ($canPickAgent && $filterAgentId !== '')
            ? $agentList->firstWhere('id', (int) $filterAgentId)
            : null;

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
            'filterAgentId', 'agentList', 'selectedAgent', 'canPickAgent',
            'filterOptions', 'filters', 'currentSort', 'currentDir'
        ));
    }

    public function show(Property $property)
    {
        $this->authorizeProperty($property);
        $property->load(['agent', 'branch', 'notes.user', 'files.user', 'contacts.type']);

        $settingItems = [
            'categories'   => PropertySettingItem::group('category')->get(),
            'types'        => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'statuses'     => PropertySettingItem::group('property_status')->get(),
            'mandateTypes' => PropertySettingItem::group('mandate_type')->get(),
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

        $readinessReport = app(\App\Services\Compliance\MarketingReadinessService::class)->statusFor($property);

        // Whistleblower compliance flags linked to this property
        $propertyComplianceComplaints = $property->exists
            ? \App\Models\Compliance\WhistleblowComplaint::withoutGlobalScopes()
                ->where('property_id', $property->id)
                ->whereIn('status', ['sent', 'acknowledged_by_ppra', 'approved'])
                ->with('reporter')
                ->orderByDesc('created_at')
                ->get()
            : collect();

        return view('corex.properties.show', compact(
            'property', 'settingItems', 'branches', 'agents', 'activeTab', 'coreMatches', 'ppMissingFields', 'p24MissingFields', 'hfcMissingFields',
            'allDriveDocs', 'documentTypes', 'driveFolders', 'activityTimeline', 'fullAuditLog', 'readinessReport', 'propertyComplianceComplaints'
        ));
    }

    public function create()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $property           = new Property();
        $property->status   = 'for_sale';
        $property->listing_type = 'Sale';
        // Province intentionally not pre-filled — user must pick from the
        // P24 cascading picker so the suburb/city/province chain is real.
        $property->agent_id = $user->id;
        $property->branch_id = $user->effectiveBranchId();
        $property->agency_id = $user->agency_id ?? null;
        $property->beds     = 0;
        $property->baths    = 0;
        $property->garages  = 0;

        // Pre-fill from contact if creating from a contact page
        $preLinkedContact = null;
        if ($contactId = request('contact_id')) {
            $contact = \App\Models\Contact::find($contactId);
            if ($contact) {
                $preLinkedContact = $contact;
                // Pre-fill address from contact if available
                if ($contact->suburb) $property->suburb = $contact->suburb;
                if ($contact->city) $property->city = $contact->city;
                if ($contact->province) $property->province = $contact->province;
                if ($contact->street_address) $property->address = $contact->street_address;
            }
        }

        $settingItems = [
            'categories'   => PropertySettingItem::group('category')->get(),
            'types'        => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'statuses'     => PropertySettingItem::group('property_status')->get(),
            'mandateTypes' => PropertySettingItem::group('mandate_type')->get(),
        ];
        $branches  = Branch::orderBy('name')->get();
        $agents    = $this->agentList($property);
        $activeTab = 'info';

        return view('corex.properties.show', compact('property', 'settingItems', 'branches', 'agents', 'activeTab', 'preLinkedContact'));
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
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'nullable|string|max:50',
            'category'         => 'nullable|string|max:100',
            'mandate_type'     => 'nullable|string|max:50',
            'listing_type'     => 'nullable|string|in:sale,rental',
            'status'           => 'nullable|string|max:100',
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
            'publish'          => 'nullable|boolean',
            'dawn_images'               => 'nullable|array',
            'dawn_images.*'             => 'image|max:51200',
            'noon_images'               => 'nullable|array',
            'noon_images.*'             => 'image|max:51200',
            'dusk_images'               => 'nullable|array',
            'dusk_images.*'             => 'image|max:51200',
            'gallery_images'            => 'nullable|array',
            'gallery_images.*'          => 'image|max:51200',
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
            $data['status']       = 'active';
        }
        unset($data['publish']);

        // Create to get ID, then attach images
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

        // Re-sync with images if published (first create had no images yet)
        if ($property->isPublished()) {
            \App\Jobs\SyncPropertyToWebsite::dispatchSync($property->fresh(['agent', 'branch', 'agency']), 'upsert');
        }

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

        // Link existing contacts selected during create
        foreach ((array) $request->input('pending_contact_ids', []) as $cid) {
            $cid = (int) $cid;
            if ($cid > 0) {
                $wasLinked = $property->contacts()->where('contacts.id', $cid)->exists();
                $property->contacts()->syncWithoutDetaching([$cid => ['role' => null]]);
                if (!$wasLinked) {
                    $linkedContact = \App\Models\Contact::find($cid);
                    if ($linkedContact) {
                        event(new \App\Events\Contact\ContactLinkedToProperty(
                            contact: $linkedContact,
                            property: $property,
                            role: 'unknown',
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
                $property->contacts()->syncWithoutDetaching([$existing->id => ['role' => null]]);
                $match = $dupService->identifyMatch($ncData, $existing, $agencyId);
                $dupService->logAttempt($agencyId, auth()->id(), 'auto_link', $match['field'], $match['value'], $existing->id, $ncData, 'auto_linked');
                if (!$wasLinked) {
                    event(new \App\Events\Contact\ContactLinkedToProperty(
                        contact: $existing,
                        property: $property,
                        role: 'unknown',
                        actorUserId: auth()->id(),
                    ));
                }
                continue;
            }
            $ncData['contact_type_id'] = !empty($nc['contact_type_id']) ? (int) $nc['contact_type_id'] : null;
            $ncData['created_by_user_id'] = auth()->id();
            $contact = \App\Models\Contact::create($ncData);
            $property->contacts()->attach($contact->id, ['role' => null]);
            event(new \App\Events\Contact\ContactLinkedToProperty(
                contact: $contact,
                property: $property,
                role: 'unknown',
                actorUserId: auth()->id(),
            ));
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
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'nullable|string|max:50',
            'category'         => 'nullable|string|max:100',
            'mandate_type'     => 'nullable|string|max:50',
            'listing_type'     => 'nullable|string|in:sale,rental',
            'status'           => 'nullable|string|max:100',
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
            'publish'          => 'nullable|boolean',
            'dawn_images'      => 'nullable|array',
            'dawn_images.*'    => 'image|max:51200',
            'noon_images'      => 'nullable|array',
            'noon_images.*'    => 'image|max:51200',
            'dusk_images'      => 'nullable|array',
            'dusk_images.*'    => 'image|max:51200',
            'gallery_images'   => 'nullable|array',
            'gallery_images.*' => 'image|max:51200',
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

        $data = $this->processSpacesJson($data);
        $data = $this->applyP24Location($data);

        if (! empty($data['publish']) && ! $property->isPublished()) {
            $data['published_at'] = now();
            $data['status']       = 'active';
        }
        unset($data['publish']);

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
            'gallery_images.*'=> 'image|max:51200',
            'dawn_images'     => 'nullable|array',
            'dawn_images.*'   => 'image|max:51200',
            'noon_images'     => 'nullable|array',
            'noon_images.*'   => 'image|max:51200',
            'dusk_images'     => 'nullable|array',
            'dusk_images.*'   => 'image|max:51200',
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

    public function reorderImages(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        // Smart gallery saves both categories and flat list
        if ($request->has('gallery_categories_json')) {
            $property->update([
                'gallery_categories_json' => $request->input('gallery_categories_json'),
                'gallery_images_json'     => $request->input('gallery_images_json', []),
            ]);

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

    public function ad(Property $property)
    {
        $this->authorizeProperty($property);
        $property->load(['agent', 'branch']);

        /** @var User $user */
        $user = auth()->user();

        // Saved custom templates: own + global ones
        $savedTemplates = PropertyAdTemplate::where('user_id', $user->id)
            ->orWhere('is_global', true)
            ->orderByDesc('updated_at')
            ->get(['id', 'user_id', 'name', 'layout_json', 'is_global', 'updated_at']);

        $canManageTemplates = $user->hasPermission('properties.view');

        return view('corex.properties.ad', compact('property', 'savedTemplates', 'canManageTemplates'));
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

    private function storeImages(Request $request, string $field, int $propertyId): array
    {
        $urls = [];
        if ($request->hasFile($field)) {
            foreach ($request->file($field) as $file) {
                $path = $file->store("properties/{$propertyId}", 'public');
                $this->downscaleStoredImage($path, 2560, 85);
                $urls[] = Storage::url($path);
            }
        }
        return $urls;
    }

    /**
     * Resize a stored image down to a sensible web size (max dimension on
     * longest edge, JPEG re-encoded at given quality). Keeps file paths /
     * extensions intact. Uses GD so no extra dependency is needed.
     * Original file is overwritten in place. Failures are swallowed so the
     * upload still succeeds — the source file simply isn't resized.
     */
    private function downscaleStoredImage(string $relativePath, int $maxEdge = 2560, int $quality = 85): void
    {
        if (!function_exists('imagecreatefromstring')) {
            return;
        }

        $disk = Storage::disk('public');
        if (!$disk->exists($relativePath)) {
            return;
        }

        $absolute = $disk->path($relativePath);

        $info = @getimagesize($absolute);
        if (!$info) {
            return;
        }
        [$width, $height] = $info;
        $maxSide = max($width, $height);

        if ($maxSide <= $maxEdge && $info[2] === IMAGETYPE_JPEG) {
            return;
        }

        $bytes = @file_get_contents($absolute);
        if ($bytes === false) {
            return;
        }
        $src = @imagecreatefromstring($bytes);
        unset($bytes);
        if (!$src) {
            return;
        }

        if ($maxSide > $maxEdge) {
            $scale     = $maxEdge / $maxSide;
            $newWidth  = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $dst       = imagecreatetruecolor($newWidth, $newHeight);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($src);
            $src = $dst;
        }

        @imagejpeg($src, $absolute, $quality);
        imagedestroy($src);
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

    private function authorizeProperty(Property $property): void
    {
        /** @var User $user */
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        if ($scope === 'all') return;
        if ($scope === 'branch' && (int) $property->branch_id === (int) $user->effectiveBranchId()) return;
        if ($scope === 'own' && (int) $property->agent_id === (int) $user->id) return;

        abort(403);
    }

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
