<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\ContactMatch;
use App\Models\Property;
use App\Models\PropertyAdTemplate;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\PermissionService;
use App\Services\PrivateProperty\PrivatePropertyListingMapper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    public function index(Request $request)
    {
        /** @var User $user */
        $user           = auth()->user();
        $dataScope      = PermissionService::getDataScope($user, 'properties');
        $viewScope      = $request->query('scope', 'my');   // 'my' | 'branch'
        $status         = $request->query('status', '');    // '' | draft | active | sold | withdrawn
        $search         = trim($request->query('search', ''));
        $filterAgentId  = $request->query('agent_id', '');  // admin/bm: view a specific agent's listings

        $query = Property::with(['agent', 'branch']);

        $canPickAgent = in_array($dataScope, ['all', 'branch']);

        // Agent filter with session persistence (admin/BM only)
        if ($canPickAgent) {
            if ($request->has('agent_id')) {
                $raw           = $request->query('agent_id', '');
                $filterAgentId = $raw;
                session(['corex_properties_agent_id' => $raw === '' ? 'all' : $raw]);
            } else {
                $saved = session('corex_properties_agent_id');
                if ($saved === null) {
                    $filterAgentId = (string) $user->id;
                    session(['corex_properties_agent_id' => $filterAgentId]);
                } elseif ($saved === 'all') {
                    $filterAgentId = '';
                } else {
                    $filterAgentId = $saved;
                }
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

        if ($status !== '') {
            $query->where('status', $status);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('suburb', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%");
            });
        }

        $properties = $query->orderByDesc('created_at')->get();

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

        $scope = $viewScope;

        return view('corex.properties.index', compact(
            'properties', 'stats', 'scope', 'status', 'search',
            'filterAgentId', 'agentList', 'selectedAgent', 'canPickAgent'
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
        $activeTab = request('tab', 'overview');

        // Find all Core Matches where this property satisfies the criteria
        $coreMatches = ContactMatch::with(['contact.type', 'createdBy'])
            ->get()
            ->filter(function (ContactMatch $m) use ($property) {
                // Skip if property is explicitly hidden in this match
                if (in_array($property->id, $m->hidden_property_ids ?? [])) return false;
                // Category
                if ($m->category && $property->category !== $m->category) return false;
                // Property type
                if ($m->property_type && $property->property_type !== $m->property_type) return false;
                // Suburb (case-insensitive contains)
                if ($m->suburb && stripos($property->suburb ?? '', $m->suburb) === false) return false;
                // Price
                if ($m->price_min && ($property->price ?? 0) < $m->price_min) return false;
                if ($m->price_max && ($property->price ?? 0) > $m->price_max) return false;
                // Beds / baths / garages
                if ($m->beds_min && ($property->beds ?? 0) < $m->beds_min) return false;
                if ($m->baths_min && ($property->baths ?? 0) < $m->baths_min) return false;
                if ($m->garages_min && ($property->garages ?? 0) < $m->garages_min) return false;
                // Floor size
                if ($m->floor_size_min && ($property->size_m2 ?? 0) < $m->floor_size_min) return false;
                if ($m->floor_size_max && ($property->size_m2 ?? 0) > $m->floor_size_max) return false;
                // Erf size
                if ($m->erf_size_min && ($property->erf_size_m2 ?? 0) < $m->erf_size_min) return false;
                if ($m->erf_size_max && ($property->erf_size_m2 ?? 0) > $m->erf_size_max) return false;
                return true;
            })
            ->values();

        // PP feed readiness check for syndication panel
        $ppMissingFields = $property->exists
            ? app(PrivatePropertyListingMapper::class)->checkReadiness($property)
            : [];

        return view('corex.properties.show', compact(
            'property', 'settingItems', 'branches', 'agents', 'activeTab', 'coreMatches', 'ppMissingFields'
        ));
    }

    public function create()
    {
        $property          = new Property();
        $property->status  = 'draft';
        $property->beds    = 0;
        $property->baths   = 0;
        $property->garages = 0;

        $settingItems = [
            'categories'   => PropertySettingItem::group('category')->get(),
            'types'        => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'statuses'     => PropertySettingItem::group('property_status')->get(),
            'mandateTypes' => PropertySettingItem::group('mandate_type')->get(),
        ];
        $branches  = Branch::orderBy('name')->get();
        $agents    = $this->agentList();
        $activeTab = 'info';

        return view('corex.properties.show', compact('property', 'settingItems', 'branches', 'agents', 'activeTab'));
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
            'rates_taxes'      => 'nullable|integer|min:0',
            'levy'             => 'nullable|integer|min:0',
            'special_levy'     => 'nullable|integer|min:0',
            'city'             => 'nullable|string|max:100',
            'suburb'           => 'required|string|max:100',
            'address'          => 'nullable|string|max:300',
            'region'           => 'nullable|string|max:100',
            'beds'             => 'required|integer|min:0|max:20',
            'baths'            => 'required|integer|min:0|max:20',
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'nullable|string|max:50',
            'category'         => 'nullable|string|max:100',
            'mandate_type'     => 'nullable|string|max:50',
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
            'expiry_date'      => 'nullable|date',
            'lease_start_date' => 'nullable|date',
            'lease_end_date'   => 'nullable|date',
            'branch_id'        => 'nullable|exists:branches,id',
            'agent_id'         => 'nullable|exists:users,id',
            'pp_second_agent_id' => 'nullable|exists:users,id',
            'rental_price_type'  => 'nullable|string|max:50',
            'pp_hide_street_name'   => 'nullable|boolean',
            'pp_hide_street_number' => 'nullable|boolean',
            'pp_hide_complex_name'  => 'nullable|boolean',
            'pp_hide_unit_number'   => 'nullable|boolean',
            'publish'          => 'nullable|boolean',
            'dawn_images'               => 'nullable|array',
            'dawn_images.*'             => 'image|max:5120',
            'noon_images'               => 'nullable|array',
            'noon_images.*'             => 'image|max:5120',
            'dusk_images'               => 'nullable|array',
            'dusk_images.*'             => 'image|max:5120',
            'gallery_images'            => 'nullable|array',
            'gallery_images.*'          => 'image|max:5120',
            // Create-form extras
            'initial_note'              => 'nullable|string|max:5000',
            'drive_files'               => 'nullable|array',
            'drive_files.*'             => 'file|max:51200',
            'pending_contact_ids'       => 'nullable|array',
            'pending_contact_ids.*'     => 'integer',
            'pending_new_contacts'      => 'nullable|array',
        ]);

        $data = $this->processSpacesJson($data);

        $storeScope = PermissionService::getDataScope($user, 'properties');
        if (! in_array($storeScope, ['all', 'branch']) || empty($data['agent_id'])) {
            $data['agent_id'] = $user->id;
        }
        $data['agency_id'] = $user->effectiveAgencyId();

        if (! empty($data['publish'])) {
            $data['published_at'] = now();
            $data['status']       = 'active';
        }
        unset($data['publish']);

        // Create to get ID, then attach images
        $property = Property::create($data);

        $property->dawn_images_json    = $this->storeImages($request, 'dawn_images',    $property->id);
        $property->noon_images_json    = $this->storeImages($request, 'noon_images',    $property->id);
        $property->dusk_images_json    = $this->storeImages($request, 'dusk_images',    $property->id);
        $property->gallery_images_json = $this->storeImages($request, 'gallery_images', $property->id);
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
                $property->contacts()->syncWithoutDetaching([$cid => ['role' => null]]);
            }
        }

        // Create + link new contacts added during create
        foreach ((array) $request->input('pending_new_contacts', []) as $nc) {
            if (empty($nc['first_name']) || empty($nc['last_name']) || empty($nc['phone'])) continue;
            $contact = \App\Models\Contact::create([
                'first_name'         => substr($nc['first_name'], 0, 100),
                'last_name'          => substr($nc['last_name'],  0, 100),
                'phone'              => substr($nc['phone'],       0, 30),
                'email'              => !empty($nc['email']) ? substr($nc['email'], 0, 150) : null,
                'contact_type_id'    => !empty($nc['contact_type_id']) ? (int) $nc['contact_type_id'] : null,
                'created_by_user_id' => auth()->id(),
            ]);
            $property->contacts()->attach($contact->id, ['role' => null]);
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

        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'excerpt'          => 'nullable|string|max:500',
            'description'      => 'nullable|string',
            'price'            => 'required|integer|min:0',
            'rates_taxes'      => 'nullable|integer|min:0',
            'levy'             => 'nullable|integer|min:0',
            'special_levy'     => 'nullable|integer|min:0',
            'city'             => 'nullable|string|max:100',
            'suburb'           => 'required|string|max:100',
            'address'          => 'nullable|string|max:300',
            'region'           => 'nullable|string|max:100',
            'beds'             => 'required|integer|min:0|max:20',
            'baths'            => 'required|integer|min:0|max:20',
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'nullable|string|max:50',
            'category'         => 'nullable|string|max:100',
            'mandate_type'     => 'nullable|string|max:50',
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
            'expiry_date'      => 'nullable|date',
            'lease_start_date' => 'nullable|date',
            'lease_end_date'   => 'nullable|date',
            'branch_id'        => 'nullable|exists:branches,id',
            'agent_id'         => 'nullable|exists:users,id',
            'pp_second_agent_id' => 'nullable|exists:users,id',
            'rental_price_type'  => 'nullable|string|max:50',
            'pp_hide_street_name'   => 'nullable|boolean',
            'pp_hide_street_number' => 'nullable|boolean',
            'pp_hide_complex_name'  => 'nullable|boolean',
            'pp_hide_unit_number'   => 'nullable|boolean',
            'publish'          => 'nullable|boolean',
            'dawn_images'      => 'nullable|array',
            'dawn_images.*'    => 'image|max:5120',
            'noon_images'      => 'nullable|array',
            'noon_images.*'    => 'image|max:5120',
            'dusk_images'      => 'nullable|array',
            'dusk_images.*'    => 'image|max:5120',
            'gallery_images'   => 'nullable|array',
            'gallery_images.*' => 'image|max:5120',
        ]);

        // Checkboxes that aren't checked don't submit — ensure they're explicitly set to false
        $data['pp_hide_street_name']   = $request->boolean('pp_hide_street_name');
        $data['pp_hide_street_number'] = $request->boolean('pp_hide_street_number');
        $data['pp_hide_complex_name']  = $request->boolean('pp_hide_complex_name');
        $data['pp_hide_unit_number']   = $request->boolean('pp_hide_unit_number');

        $data = $this->processSpacesJson($data);

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
        if ($newGallery) $data['gallery_images_json'] = array_merge($property->gallery_images_json ?? [], $newGallery);

        $property->update($data);

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
        $this->authorizeProperty($property);
        $property->load(['agent', 'branch', 'agency']);

        /** @var User $authUser */
        $authUser = auth()->user();

        $agentChoice  = $request->query('agent', 'listing');
        $displayAgent = ($agentChoice === 'me') ? $authUser : ($property->agent ?? $authUser);

        return view('corex.properties.live-preview', compact('property', 'displayAgent', 'agentChoice'));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

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
                $path   = $file->store("properties/{$propertyId}", 'public');
                $urls[] = Storage::url($path);
            }
        }
        return $urls;
    }

    private function agentList(): \Illuminate\Support\Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $scope = PermissionService::getDataScope($user, 'properties');

        $query = User::orderBy('name')->where('is_active', 1);

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        } elseif ($scope !== 'all') {
            $query->where('id', $user->id);
        }

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
}
