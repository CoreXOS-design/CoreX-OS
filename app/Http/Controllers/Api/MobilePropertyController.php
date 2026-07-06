<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Property;
use App\Models\PropertySellerLink;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\Compliance\MarketingReadinessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MobilePropertyController extends Controller
{
    // Same chain-verifier the web create/edit form + quick-setup wizard use.
    // Verifies suburb → city → province and overwrites the denormalised
    // suburb/city/province text columns with canonical P24 names.
    use \App\Http\Concerns\AppliesP24Location;
    use \App\Http\Controllers\Api\Concerns\ResolvesMobileDataScope;

    // ── GET /api/mobile/properties ───────────────────────────────
    //
    // Visibility (Property has NO global scope, so we enforce it here, the
    // same way the web CoreX\PropertyController does):
    //   ?agent_id absent  → the user's own listings (default, like the web)
    //   ?agent_id=        → everything the role scope allows (branch/agency)
    //   ?agent_id=123     → that agent's listings (if in scope, else 403)
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scope = \App\Services\PermissionService::getDataScope($user, 'properties') ?? 'own';
        $agentFilter = $this->resolveAgentFilter(
            $user,
            'properties',
            $request->has('agent_id') ? $request->query('agent_id', '') : null
        );

        $query = Property::query();
        if ($agentFilter !== null) {
            // Co-listing rule (mirrors the web PropertyController): a property
            // may carry a secondary (co-listing) agent on pp_second_agent_id.
            // When scoping to one agent, match whether they are the PRIMARY
            // (agent_id) OR the SECONDARY, so a co-listed property appears under
            // both agents' "My properties". A property is a single row, so the
            // OR still returns it exactly once.
            $query->where(function ($q) use ($agentFilter) {
                $q->where('agent_id', $agentFilter)
                  ->orWhere('pp_second_agent_id', $agentFilter);
            });
        } elseif ($scope === 'branch') {
            $query->where('branch_id', $user->effectiveBranchId() ?: -1);
        }
        // $scope === 'all' with no agent filter → agency-wide (AgencyScope
        // still isolates cross-agency).

        // Free-text search (?q= or ?search=) — the calendar add-event sheet's
        // property picker calls this. Uses the ONE canonical property search
        // scope (scopeSearchAddress) so every picker matches identically.
        $term = trim((string) ($request->query('q') ?? $request->query('search') ?? ''));
        if ($term !== '') {
            $query->searchAddress($term);
        }

        $properties = $query
            ->orderByDesc('updated_at')
            ->get([
                'id', 'title', 'address', 'street_number', 'street_name',
                'suburb', 'city', 'complex_name', 'unit_number',
                'beds', 'baths', 'garages', 'status', 'property_type',
                'category', 'listing_type', 'price', 'agent_id',
                // All image groups so the thumbnail matches the web card,
                // which uses allImages()[0] (dawn→noon→dusk→gallery→images).
                'gallery_images_json', 'dawn_images_json', 'noon_images_json',
                'dusk_images_json', 'images_json', 'updated_at',
            ])
            ->map(fn (Property $p) => [
                'id'            => $p->id,
                'address'       => $p->buildDisplayAddress(),
                'beds'          => $p->beds,
                'baths'         => $p->baths,
                'garages'       => $p->garages,
                'status'        => $p->status,
                'property_type' => $p->property_type,
                'category'      => $p->category,
                'listing_type'  => $p->listing_type,
                'price'         => $p->price,
                'price_display' => $p->formattedPrice(),
                // Same first image as the web listing card, as an absolute URL
                // so it loads on a mobile device (relative /storage paths don't).
                'thumbnail'     => $this->coverImageUrl($p),
                'updated_at'    => $p->updated_at?->toIso8601String(),
            ]);

        return response()->json(['properties' => $properties]);
    }

    // ── GET /api/mobile/properties/{id} ─────────────────────────
    public function show(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        return response()->json([
            'property' => $this->fullPropertyResponse($property),
        ]);
    }

    // ── POST /api/mobile/properties ─────────────────────────────
    // Create a brand-new property. The mobile must send the same minimum
    // set of fields the web requires (title, property_type, listing_type,
    // status, suburb, price) — anything less and the property would be
    // unusable on the web side.
    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rules = $this->propertyRules(isCreate: true) + [
            'link_contact_id'   => 'nullable|integer|exists:contacts,id',
            'link_contact_role' => 'nullable|string|max:50',
        ];
        $data = $request->validate($rules);

        $linkContactId   = $data['link_contact_id']   ?? null;
        $linkContactRole = $data['link_contact_role'] ?? null;
        unset($data['link_contact_id'], $data['link_contact_role']);

        // Server fills these — never trust the client
        $data['agent_id']  = $user->id;
        $data['branch_id'] = $user->effectiveBranchId();
        $data['agency_id'] = $user->agency_id ?? null;

        // Verify the P24 chain and canonicalise suburb/city/province.
        // Required on create — same rule the web form enforces.
        $data = $this->applyP24Location($data, required: true);

        $data = $this->mapPayloadToColumns($data);

        $property = Property::create($data);

        // Cross-pillar reactivity — emit the same domain event the web
        // create path emits so suburb-linked listeners stay in sync.
        if ($property->p24_suburb_id) {
            event(new \App\Events\Property\PropertySuburbLinked(
                property: $property,
                previousP24SuburbId: null,
                newP24SuburbId: (int) $property->p24_suburb_id,
                actorUserId: $user->id,
            ));
        }

        if ($linkContactId) {
            $contact = \App\Models\Contact::find($linkContactId);
            if ($contact && $contact->created_by_user_id === $user->id) {
                $property->contacts()->attach($contact->id, ['role' => $linkContactRole]);
            }
        }

        $property->refresh();

        return response()->json([
            'property' => $this->fullPropertyResponse($property),
        ], 201);
    }

    // ── PUT /api/mobile/properties/{id} ─────────────────────────
    // Edit an existing property. Same field set as create, but every
    // field is optional — only send what changed (PATCH-style semantics).
    public function update(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $data = $request->validate($this->propertyRules(isCreate: false));

        // Only re-verify the P24 chain if the client actually sent a new
        // suburb selection (PATCH-style — untouched location stays as-is).
        $previousP24SuburbId = $property->p24_suburb_id;
        if (array_key_exists('p24_suburb_id', $data) && (int) $data['p24_suburb_id'] > 0) {
            $data = $this->applyP24Location($data, required: true);
        } else {
            // Don't let a partial update wipe the canonical location.
            unset($data['p24_suburb_id'], $data['p24_city_id'], $data['p24_province_id'],
                  $data['suburb'], $data['city'], $data['province']);
        }

        $data = $this->mapPayloadToColumns($data);

        $property->update($data);

        if (isset($data['p24_suburb_id'])
            && (int) $data['p24_suburb_id'] > 0
            && (int) $previousP24SuburbId !== (int) $data['p24_suburb_id']) {
            event(new \App\Events\Property\PropertySuburbLinked(
                property: $property,
                previousP24SuburbId: $previousP24SuburbId ? (int) $previousP24SuburbId : null,
                newP24SuburbId: (int) $data['p24_suburb_id'],
                actorUserId: $request->user()->id,
            ));
        }

        $property->refresh();

        return response()->json([
            'property' => $this->fullPropertyResponse($property),
        ]);
    }

    /**
     * Validation rules shared by store + update.
     *
     * On create, the same fields the web form requires (title,
     * property_type, listing_type, status, suburb, price) are required.
     * On update, every field is optional — the client only sends what
     * changed.
     */
    private function propertyRules(bool $isCreate): array
    {
        $req = $isCreate ? 'required' : 'sometimes';

        return [
            // Required-on-create fields
            'title'         => "{$req}|string|max:255",
            'property_type' => "{$req}|string|max:100",
            'listing_type'  => "{$req}|string|in:sale,rental",
            'status'        => "{$req}|string|max:50",
            'price'         => "{$req}|integer|min:0",

            // ── Property24 location (the spine of suburb/city/province) ──
            // Mirrors the web create/edit form: the property MUST land on a
            // P24-recognised suburb. The client picks province → city →
            // suburb from GET /api/mobile/p24/{provinces,cities,suburbs}
            // and sends back the IDs. The applyP24Location() trait then
            // verifies the chain and OVERWRITES suburb/city/province with
            // the canonical P24 names — the client never sets those as
            // free text any more.
            'p24_province_id' => 'nullable|integer|exists:p24_provinces,id',
            'p24_city_id'     => 'nullable|integer|exists:p24_cities,id',
            'p24_suburb_id'   => "{$req}|integer|exists:p24_suburbs,id",

            // Derived from the P24 chain — accepted but always overwritten
            // by applyP24Location(). Kept nullable so old clients don't 422.
            'suburb'        => 'nullable|string|max:255',

            // Address & location
            'street_number' => 'nullable|string|max:20',
            'street_name'   => 'nullable|string|max:255',
            'address'       => 'nullable|string|max:500',
            'city'          => 'nullable|string|max:255',
            'province'      => 'nullable|string|max:100',
            'region'        => 'nullable|string|max:255',
            'district'      => 'nullable|string|max:255',
            'complex_name'  => 'nullable|string|max:255',
            'unit_number'   => 'nullable|string|max:50',

            // Counts & sizes
            'beds'          => 'nullable|integer|min:0|max:50',
            'baths'         => 'nullable|numeric|min:0|max:50',
            'garages'       => 'nullable|integer|min:0|max:20',
            'size_m2'       => 'nullable|numeric|min:0',
            'erf_size_m2'   => 'nullable|numeric|min:0',

            // Classification
            'category'      => 'nullable|string|max:100',
            'mandate_type'  => 'nullable|string|max:100',

            // Content
            'excerpt'       => 'nullable|string|max:500',
            'description'   => 'nullable|string|max:10000',

            // Rental-only (ignored if listing_type === 'sale')
            'rental_amount'    => 'nullable|numeric|min:0',
            'deposit_amount'   => 'nullable|numeric|min:0',
            'lease_start_date' => 'nullable|date',
            'lease_end_date'   => 'nullable|date|after_or_equal:lease_start_date',

            // Commission / fees
            'commission_percent' => 'nullable|numeric|min:0|max:100',
            'admin_fee'          => 'nullable|numeric|min:0',
            'marketing_fee'      => 'nullable|numeric|min:0',

            // Flat features list (the global ones — the property,
            // security, connectivity, sustainability)
            'features'   => 'nullable|array',
            'features.*' => 'string|max:255',

            // Optional one-shot spaces payload — same shape as the
            // dedicated /spaces endpoint accepts. If supplied here on
            // create, the property is born with its spaces already set.
            'spaces_json'                          => 'nullable|array',
            'spaces_json.spaces'                   => 'nullable|array',
            'spaces_json.spaces.*.type'            => 'required_with:spaces_json.spaces|string|max:100',
            'spaces_json.spaces.*.count'           => 'required_with:spaces_json.spaces|numeric|min:0|max:100',
            'spaces_json.spaces.*.featuresAll'     => 'nullable|array',
            'spaces_json.spaces.*.featuresAll.*'   => 'string|max:255',
            'spaces_json.spaces.*.descriptionAll'  => 'nullable|string|max:5000',
            'spaces_json.spaces.*.units'           => 'nullable|array',
            'spaces_json.spaces.*.units.*.label'   => 'nullable|string|max:255',
            'spaces_json.spaces.*.units.*.features'   => 'nullable|array',
            'spaces_json.spaces.*.units.*.features.*' => 'string|max:255',
            'spaces_json.features'                 => 'nullable|array',
            'spaces_json.features.theProperty'     => 'nullable|array',
            'spaces_json.features.theProperty.*'   => 'string|max:255',
            'spaces_json.features.security'        => 'nullable|array',
            'spaces_json.features.security.*'      => 'string|max:255',
            'spaces_json.features.connectivity'    => 'nullable|array',
            'spaces_json.features.connectivity.*'  => 'string|max:255',
            'spaces_json.features.sustainability'  => 'nullable|array',
            'spaces_json.features.sustainability.*'=> 'string|max:255',
        ];
    }

    /**
     * Convert the validated payload to actual model column names.
     * `features` → `features_json`, `spaces_json` is normalized via the
     * same helper the dedicated /spaces endpoint uses.
     */
    private function mapPayloadToColumns(array $data): array
    {
        if (isset($data['features'])) {
            $data['features_json'] = $data['features'];
            unset($data['features']);
        }

        if (isset($data['spaces_json'])) {
            $data['spaces_json'] = $this->normalizeSpacesPayload($data['spaces_json']);

            // Sync legacy bed/bath/garage columns from the spaces payload
            // so the rest of the system stays correct (search, listings,
            // syndication all read these directly off the row).
            $bedSpace  = collect($data['spaces_json']['spaces'])->firstWhere('type', 'Bedroom');
            $bathSpace = collect($data['spaces_json']['spaces'])->firstWhere('type', 'Bathroom');
            $garSpace  = collect($data['spaces_json']['spaces'])->firstWhere('type', 'Garage');
            if ($bedSpace)  $data['beds']    = (int) floor($bedSpace['count']);
            if ($bathSpace) $data['baths']   = (int) floor($bathSpace['count']);
            if ($garSpace)  $data['garages'] = (int) floor($garSpace['count']);
        }

        return $data;
    }

    // ── GET /api/mobile/properties/options ─────────────────────────
    // Returns every dropdown option the mobile create/edit screen needs:
    // categories, property types, statuses, mandate types, and the
    // fixed listing-type enum. Pulls from `property_setting_items` so
    // the agency admins can manage these from the web settings UI and
    // the mobile picks up changes automatically.
    public function options(Request $request): JsonResponse
    {
        $map = function (PropertySettingItem $item) {
            return [
                'id'         => $item->id,
                'name'       => $item->name,
                'sort_order' => $item->sort_order,
                'is_default' => (bool) $item->is_default,
            ];
        };

        $statuses = PropertySettingItem::group(PropertySettingItem::GROUP_STATUS)
            ->where('active', true)
            ->get()
            ->map(function (PropertySettingItem $item) {
                // Web stores status as a slug (strtolower + spaces→underscores).
                // Mobile must send the slug back as `status` on create/update.
                return [
                    'id'         => $item->id,
                    'name'       => $item->name,                                              // "For Sale"
                    'value'      => strtolower(str_replace(' ', '_', $item->name)),           // "for_sale"
                    'sort_order' => $item->sort_order,
                    'is_default' => (bool) $item->is_default,
                ];
            })
            ->values();

        return response()->json([
            'categories' => PropertySettingItem::group(PropertySettingItem::GROUP_CATEGORY)
                ->get()->map($map)->values(),

            'property_types' => PropertySettingItem::group(PropertySettingItem::GROUP_TYPE)
                ->where('active', true)
                ->get()->map($map)->values(),

            'statuses' => $statuses,

            'mandate_types' => PropertySettingItem::group(PropertySettingItem::GROUP_MANDATE_TYPE)
                ->get()->map($map)->values(),

            // Fixed enum on the web — mobile must send one of these as
            // `listing_type` when creating/updating a property.
            'listing_types' => [
                ['value' => 'sale',   'label' => 'For Sale'],
                ['value' => 'rental', 'label' => 'For Rental'],
            ],
        ]);
    }

    // ── GET /api/mobile/properties/spaces/catalog ──────────────────
    // Returns the full static catalog: every space type the user can add,
    // plus the feature options grouped per space type. Mobile clients call
    // this once on app start (or cache it) to render the dropdown / picker.
    public function spacesCatalog(Request $request): JsonResponse
    {
        $cfg = config('property-spaces');

        return response()->json([
            'all_space_types'        => $cfg['all_space_types'],
            'half_unit_spaces'       => $cfg['half_unit_spaces'],
            'space_features'         => $cfg['space_features'],
            'default_space_features' => $cfg['default_space_features'],
            'feature_categories'     => $cfg['feature_categories'],
        ]);
    }

    // ── GET /api/mobile/properties/{id}/spaces ─────────────────────
    // Returns the property's current spaces & global features in the
    // same shape the web stores in `spaces_json`.
    public function spacesShow(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        return response()->json([
            'property_id' => $property->id,
            'spaces_json' => $this->normalizeSpacesPayload($property->spaces_json ?? []),
            'beds'        => $property->beds,
            'baths'       => $property->baths,
            'garages'     => $property->garages,
        ]);
    }

    // ── PUT /api/mobile/properties/{id}/spaces ─────────────────────
    // Replaces the entire spaces_json for a property. Mobile sends the
    // full { spaces: [...], features: {...} } object back. We also keep
    // the legacy beds/baths/garages columns in sync so the rest of the
    // web UI (search, listings, syndication) stays correct.
    public function spacesUpdate(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $data = $request->validate([
            'spaces'                       => 'required|array',
            'spaces.*.type'                => 'required|string|max:100',
            'spaces.*.count'               => 'required|numeric|min:0|max:100',
            'spaces.*.featuresAll'         => 'nullable|array',
            'spaces.*.featuresAll.*'       => 'string|max:255',
            'spaces.*.descriptionAll'      => 'nullable|string|max:5000',
            'spaces.*.units'               => 'nullable|array',
            'spaces.*.units.*.label'      => 'nullable|string|max:255',
            'spaces.*.units.*.features'   => 'nullable|array',
            'spaces.*.units.*.features.*' => 'string|max:255',

            'features'                       => 'nullable|array',
            'features.theProperty'           => 'nullable|array',
            'features.theProperty.*'         => 'string|max:255',
            'features.security'              => 'nullable|array',
            'features.security.*'            => 'string|max:255',
            'features.connectivity'          => 'nullable|array',
            'features.connectivity.*'        => 'string|max:255',
            'features.sustainability'        => 'nullable|array',
            'features.sustainability.*'      => 'string|max:255',
        ]);

        $payload = $this->normalizeSpacesPayload([
            'spaces'   => $data['spaces'],
            'features' => $data['features'] ?? [],
        ]);

        $property->spaces_json = $payload;

        // Keep legacy columns in sync — web UI, search, and syndication
        // still read these directly off the property row.
        $bedSpace   = collect($payload['spaces'])->firstWhere('type', 'Bedroom');
        $bathSpace  = collect($payload['spaces'])->firstWhere('type', 'Bathroom');
        $garSpace   = collect($payload['spaces'])->firstWhere('type', 'Garage');

        if ($bedSpace)  $property->beds    = (int) floor($bedSpace['count']);
        if ($bathSpace) $property->baths   = (int) floor($bathSpace['count']);
        if ($garSpace)  $property->garages = (int) floor($garSpace['count']);

        $property->save();
        $property->refresh();

        return response()->json([
            'property_id' => $property->id,
            'spaces_json' => $property->spaces_json,
            'beds'        => $property->beds,
            'baths'       => $property->baths,
            'garages'     => $property->garages,
        ]);
    }

    // Normalize an incoming spaces payload to the canonical shape so the
    // web reader and the mobile reader always agree.
    private function normalizeSpacesPayload(array $raw): array
    {
        $spaces = $raw['spaces'] ?? [];
        // Tolerate the legacy shape where the JSON was just a list of spaces
        if (empty($spaces) && isset($raw[0]['type'])) {
            $spaces = $raw;
        }

        $normalized = [];
        foreach ($spaces as $sp) {
            $type  = (string) ($sp['type'] ?? '');
            if ($type === '') continue;
            $count = (float) ($sp['count'] ?? 0);

            $units = [];
            $ceil  = (int) ceil($count);
            $rawUnits = $sp['units'] ?? [];
            for ($i = 0; $i < $ceil; $i++) {
                $units[] = [
                    'label'    => $rawUnits[$i]['label']    ?? ($type . ' ' . ($i + 1)),
                    'features' => array_values($rawUnits[$i]['features'] ?? []),
                ];
            }

            $normalized[] = [
                'type'           => $type,
                'count'          => $count,
                'featuresAll'    => array_values($sp['featuresAll']    ?? []),
                'descriptionAll' => (string) ($sp['descriptionAll']    ?? ''),
                'units'          => $units,
            ];
        }

        return [
            'spaces'   => $normalized,
            'features' => [
                'theProperty'    => array_values($raw['features']['theProperty']    ?? []),
                'security'       => array_values($raw['features']['security']       ?? []),
                'connectivity'   => array_values($raw['features']['connectivity']   ?? []),
                'sustainability' => array_values($raw['features']['sustainability'] ?? []),
            ],
        ];
    }

    // ── GET /api/mobile/properties/{id}/gallery/tags ───────────────
    // Returns ONLY the gallery tags currently valid for this property,
    // i.e. derived from the spaces the agent has actually added. Mobile
    // calls this right before opening the upload sheet so the dropdown
    // can never offer a tag that doesn't exist on the property — no more
    // "Pool" tag on a property without a pool.
    public function galleryTags(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $tags = $property->getAvailableGalleryTags();

        // Also send back the tag → image mapping so the mobile UI can
        // show how many photos already live under each tag.
        $cats   = $property->gallery_categories_json ?? ['categories' => [], 'unsorted' => []];
        $counts = [];
        foreach ($cats['categories'] ?? [] as $cat) {
            $counts[$cat['name']] = count($cat['images'] ?? []);
        }

        return response()->json([
            'property_id'    => $property->id,
            'available_tags' => $tags,
            'tag_counts'     => (object) $counts,
            'untagged_count' => count($cats['unsorted'] ?? []),
        ]);
    }

    // ── POST /api/mobile/properties/{id}/images ─────────────────
    // Uploads ONE image. `room_tag` is optional:
    //   - omit it     → image lands in the "unsorted" bucket
    //   - provide it  → image is filed under that tag
    // If a tag is provided, it MUST be in the property's current
    // available_tags list (use GET /gallery/tags to fetch). 422 otherwise,
    // so the mobile can't accidentally create a tag for a space that
    // doesn't exist on the property.
    public function uploadImage(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $request->validate([
            'image'    => 'required|image|max:10240',
            'room_tag' => 'nullable|string|max:100',
        ]);

        $roomTag = $request->input('room_tag');

        if ($roomTag !== null && $roomTag !== '') {
            $available = $property->getAvailableGalleryTags();
            if (!in_array($roomTag, $available, true)) {
                return response()->json([
                    'message' => "Tag '{$roomTag}' is not available on this property. Add the matching space first.",
                    'errors'  => ['room_tag' => ["Tag '{$roomTag}' is not on this property's space list."]],
                    'available_tags' => $available,
                ], 422);
            }
        }

        $file = $request->file('image');
        $path = $file->store("properties/{$property->id}", 'public');
        $url  = Storage::url($path);

        // List-view thumbnail (original untouched).
        app(\App\Services\Images\PropertyThumbnailService::class)->generateForUrl($url);

        // Append to flat gallery list
        $gallery   = $property->gallery_images_json ?? [];
        $gallery[] = $url;
        $property->gallery_images_json = $gallery;

        // Tag into category if room_tag provided
        if ($roomTag) {
            $cats  = $property->gallery_categories_json ?? ['categories' => [], 'unsorted' => []];
            $found = false;

            foreach ($cats['categories'] as &$cat) {
                if ($cat['name'] === $roomTag) {
                    $cat['images'][] = $url;
                    $found = true;
                    break;
                }
            }
            unset($cat);

            if (! $found) {
                $cats['categories'][] = ['name' => $roomTag, 'images' => [$url]];
            }

            $property->gallery_categories_json = $cats;
        } else {
            // No tag — add to unsorted
            $cats = $property->gallery_categories_json ?? ['categories' => [], 'unsorted' => []];
            $cats['unsorted'][] = $url;
            $property->gallery_categories_json = $cats;
        }

        $property->saveQuietly();

        // Queue AI vision analysis (gated by agency flag + user permission)
        $analysisId = null;
        $u = $request->user();
        if ($u?->agency?->ai_image_recognition_enabled && $u->hasPermission('use_property_image_ai')) {
            $analysis = \App\Models\PropertyImageAnalysis::create([
                'agency_id'   => $property->agency_id,
                'property_id' => $property->id,
                'image_path'  => $path,
                'status'      => 'queued',
            ]);
            // Dispatch to the DEFAULT queue — the production/staging workers run
            // `queue:work` with no --queue flag, so they only drain `default`.
            // A dedicated `ai` queue here would sit unprocessed forever (the
            // original cause of "AI suggestions never appear"). If a dedicated
            // AI worker is added later, give it `--queue=ai,default` and restore
            // ->onQueue('ai') here.
            \App\Jobs\AnalysePropertyImageJob::dispatch($analysis->id);
            $analysisId = $analysis->id;
        }

        return response()->json([
            'message'     => 'Image uploaded.',
            // Absolute so the app can render it straight back without a reload.
            'url'         => $this->absoluteImageUrl($url),
            'room_tag'    => $roomTag,
            'analysis_id' => $analysisId,
        ], 201);
    }

    // ── Response helpers ───────────────────────────────────────
    // ── GET /api/mobile/properties/{id}/overview ────────────────────
    // Returns the data the mobile app's Overview screen needs in one call:
    // identity, primary stats, listing agent, owner contact, key dates,
    // and an array of `placements` — one entry per portal the property
    // is currently LIVE on. Portals that are off / unsubmitted are omitted
    // (so the UI can render "no place to go" cleanly).
    public function overview(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);
        $property->load(['agent', 'branch', 'contacts.type']);

        // Match the web listing card exactly: first of allImages() (dawn →
        // noon → dusk → gallery → images), returned as an absolute URL.
        $coverImage = $this->coverImageUrl($property);
        $allImages  = $property->allImages();
        $daysOnMarket = $property->listed_date ? (int) $property->listed_date->diffInDays(now()) : null;

        // Owner contact: prefer role-tagged seller/landlord/owner, else the first linked contact.
        $ownerRoles = ['seller', 'landlord', 'owner'];
        $owner = $property->contacts->first(fn($c) => in_array(strtolower($c->pivot->role ?? ''), $ownerRoles))
                 ?? $property->contacts->first();
        $ownerName = null;
        if ($owner) {
            $ownerName = trim($owner->full_name ?? '') ?: trim(($owner->first_name ?? '') . ' ' . ($owner->last_name ?? ''))
                      ?: ($owner->email ?: $owner->phone ?: 'Unnamed contact');
        }

        // Live preview URL (always available even before publish).
        $livePreviewUrl = route('corex.properties.preview', [$property, \Illuminate\Support\Str::slug($property->title ?: 'property')]);

        // Canonical, extensible portal links (website + P24 + PP + any future
        // portal) from the single source of truth on the model.
        $portalLinks = $property->portalLinks();
        // Back-compat: the existing app reads `placements` (live portals only).
        // Derived from the same source so the two never drift.
        $placements  = array_values(array_filter(
            $portalLinks,
            fn (array $l) => $l['status'] === 'live'
        ));

        return response()->json([
            'id'             => $property->id,
            'title'          => $property->title,
            'address'        => $property->buildDisplayAddress(),
            'suburb'         => $property->suburb,
            'city'           => $property->city,
            'province'       => $property->province,

            'price'          => $property->price,
            'price_display'  => $property->formattedPrice(),
            'listing_type'   => $property->listing_type,
            'status'         => $property->status,
            'mandate_type'   => $property->mandate_type,
            'property_type'  => $property->property_type,
            'category'       => $property->category,

            'beds'           => (int) $property->beds,
            'baths'          => (int) $property->baths,
            'garages'        => (int) $property->garages,
            'size_m2'        => $property->size_m2,
            'erf_size_m2'    => $property->erf_size_m2,

            // Rental inspection galleries gate — same flag as the show payload,
            // here at top level (overview has no nested `property` wrapper). The
            // app reads this to decide whether to render the Inspections entry.
            // Single source of truth: Property::rentalInspectionsAvailable().
            'rental_inspections_available' => $property->rentalInspectionsAvailable(),

            'description'    => $property->description,
            'cover_image'    => $coverImage,
            'photo_count'    => count($allImages),

            'days_on_market' => $daysOnMarket,
            'key_dates' => [
                'listed'   => $property->listed_date?->toDateString(),
                'expires'  => $property->expiry_date?->toDateString(),
                'loaded'   => $property->created_at?->toIso8601String(),
                'modified' => $property->updated_at?->toIso8601String(),
            ],

            'agent' => $property->agent ? [
                'id'        => $property->agent->id,
                'name'      => $property->agent->name,
                'phone'     => $property->agent->phone,
                'email'     => $property->agent->email,
                'photo_url' => method_exists($property->agent, 'profilePhotoUrl')
                    ? $property->agent->profilePhotoUrl()
                    : ($property->agent->profile_photo_url ?? null),
            ] : null,

            'branch' => $property->branch ? [
                'id'   => $property->branch->id,
                'name' => $property->branch->name,
            ] : null,

            'owner' => $owner ? [
                'id'    => $owner->id,
                'name'  => $ownerName,
                'role'  => $owner->pivot->role ?: 'Linked Contact',
                'phone' => $owner->phone,
                'email' => $owner->email,
            ] : null,

            'live_preview_url' => $livePreviewUrl,
            'virtual_tour_url' => $property->virtual_tour_url,
            'youtube_video_id' => $property->youtube_video_id,
            'matterport_id'    => $property->matterport_id,

            // `placements` is the legacy key (live portals only). `portal_links`
            // is the full, canonical list — every portal with a live/not_published
            // status — and is what new clients should read.
            'placements'   => $placements,
            'portal_links' => $portalLinks,
        ]);
    }

    // ── GET /api/mobile/properties/{id}/portal-links ───────────────
    // Dedicated endpoint for the property's public portal links: company
    // website, Property24, Private Property, and any future portal — all
    // from Property::portalLinks() (single source of truth). Each entry is
    // { portal, label, status, url, ref }; `url` is non-null only when the
    // listing is live on that portal. New portals appear here automatically.
    public function portalLinks(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        return response()->json([
            'property_id'  => $property->id,
            'portal_links' => $property->portalLinks(),
        ]);
    }

    // ── GET /api/mobile/properties/{id}/compliance ─────────────────
    // The full marketing-readiness / compliance report for the Overview
    // screen. Same gates the web Compliance Status panel evaluates:
    //   - authority_to_market  (signed mandate OR marketing permission)
    //   - fica_sellers         (every linked seller FICA-approved)
    //   - photos               (>= 4 uploaded)
    //   - details_complete     (required listing fields filled)
    // Returns the gate checklist, what's blocking, the next actions, plus
    // a per-seller FICA breakdown and a photo count so the mobile can
    // render the same status chips the web shows.
    public function compliance(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $report = app(MarketingReadinessService::class)->statusFor($property);

        // Per-seller FICA breakdown (drives the "FICA" rows on Overview).
        $sellers = $property->contacts()
            ->wherePivotIn('role', ['owner', 'seller', 'landlord', 'lessor'])
            ->get()
            ->map(function (Contact $c) {
                $latest = DB::table('fica_submissions')
                    ->where('contact_id', $c->id)
                    ->orderByDesc('id')
                    ->value('status');

                return [
                    'contact_id'  => $c->id,
                    'name'        => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                    'role'        => $c->pivot->role,
                    'fica_status' => $latest ?: 'none',
                    'fica_passed' => $latest === 'approved',
                ];
            })->values();

        $photoCount = count($property->gallery_images_json ?? [])
                    + count($property->images_json ?? []);

        // Snapshot attribution — mirrors the web panel's
        // "Compliance Live — captured {date} by {name}" header so the mobile
        // can render the same LIVE state. Derive a single status label too so
        // the app doesn't have to re-compute LIVE/READY/BLOCKED itself.
        $isLive  = $property->compliance_snapshot_at !== null;
        $isReady = $report->ready && !$isLive;
        $statusLabel = $isLive ? 'LIVE' : ($isReady ? 'READY' : 'BLOCKED');

        return response()->json([
            'property_id'  => $property->id,
            'status'       => $statusLabel,         // LIVE | READY | BLOCKED
            'marketable'   => $report->ready || $isLive,
            'ready'        => $report->ready,
            'snapshot_at'  => $report->snapshotAt?->toIso8601String(),
            'snapshotted_by' => $isLive
                ? ($property->compliance_snapshot_data['snapshotted_by_name'] ?? null)
                : null,
            'first_marketed_at' => $property->first_marketed_at?->toIso8601String(),
            'blocked_by'   => $report->blockedBy,
            'next_actions' => $report->nextActions,
            'checklist'    => $report->checklist,   // {gate: {passed, detail}}
            'photos'       => [
                'count'    => $photoCount,
                'required' => 4,
                'passed'   => $photoCount >= 4,
            ],
            'sellers'      => $sellers,
        ]);
    }

    // ── POST /api/mobile/properties/{id}/compliance/send-to-market ──
    // The "Send Authority to Market" / go-live action. Mirrors the web:
    // takes the compliance snapshot (freezes the cleared state + stamps
    // first_marketed_at). If any gate fails the MarketingBlockedException
    // renders itself as a 422 with { blocked_by, report } so the mobile
    // can show exactly what's outstanding. On success the property is
    // marketable and the portal-syndication toggles become available.
    public function sendToMarket(Request $request, Property $property): JsonResponse
    {
        $user = $request->user();
        $this->authorizeProperty($user, $property);

        // Throws MarketingBlockedException (auto-renders 422) when not ready.
        app(MarketingReadinessService::class)->snapshotCompliance($property, $user);

        $property->refresh();

        return response()->json([
            'message'           => 'Property cleared and sent to market.',
            'property_id'       => $property->id,
            'marketable'        => true,
            'snapshot_at'       => $property->compliance_snapshot_at?->toIso8601String(),
            'first_marketed_at' => $property->first_marketed_at?->toIso8601String(),
        ]);
    }

    // ── GET /api/mobile/properties/{id}/contacts ───────────────────
    // Contacts linked to the property with their pivot role + latest
    // FICA status — feeds the Overview "Owner / Contacts" block.
    public function contactsIndex(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $contacts = $property->contacts()->get()->map(function (Contact $c) {
            $fica = DB::table('fica_submissions')
                ->where('contact_id', $c->id)
                ->orderByDesc('id')
                ->value('status');

            return [
                'id'         => $c->id,
                'first_name' => $c->first_name,
                'last_name'  => $c->last_name,
                'full_name'  => trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? '')),
                'phone'      => $c->phone,
                'email'      => $c->email,
                'role'       => $c->pivot->role,
                'type'       => $c->type?->name,
                'fica_status'=> $fica ?: 'none',
            ];
        })->values();

        return response()->json([
            'property_id' => $property->id,
            'contacts'    => $contacts,
        ]);
    }

    // ── POST /api/mobile/properties/{id}/contacts ──────────────────
    // Link a contact to the property. Two modes:
    //   A) Existing contact → send { "contact_id": 123, "role": "seller" }
    //   B) New contact      → send the contact fields (first_name, …) and
    //                          they're created, then linked, in one call.
    // `role` is one of: owner, seller, landlord, lessor, buyer, tenant,
    // bond_originator, attorney … (free string, max 50 — same as web).
    // Seller-type roles also auto-create the PropertySellerLink the
    // compliance/FICA flow keys off (mirrors the web link controller).
    public function contactsLink(Request $request, Property $property): JsonResponse
    {
        $user = $request->user();
        $this->authorizeProperty($user, $property);

        $data = $request->validate([
            'contact_id'      => 'nullable|integer|exists:contacts,id',
            'role'            => 'nullable|string|max:50',

            // New-contact fields (required only when contact_id is absent)
            'first_name'      => 'required_without:contact_id|string|max:100',
            'last_name'       => 'required_without:contact_id|string|max:100',
            'phone'           => 'required_without:contact_id|string|max:30',
            'email'           => 'nullable|email|max:150',
            'id_number'       => 'nullable|string|max:20',
            'contact_type_id' => 'nullable|exists:contact_types,id',
            'notes'           => 'nullable|string|max:1000',
        ]);

        $role = $data['role'] ?? null;

        if (!empty($data['contact_id'])) {
            $contact = Contact::findOrFail($data['contact_id']);
        } else {
            // Inline-create — same duplicate guard as the mobile contacts endpoint.
            // AT-125 — match ALL of every contact's identifiers (child tables + mirror).
            $dup = app(\App\Services\ContactDuplicateService::class)
                ->findDuplicatesForIdentifiers(
                    array_values(array_filter([$data['phone'] ?? null])),
                    array_values(array_filter([$data['email'] ?? null])),
                    $data['id_number'] ?? null,
                    (int) $user->agency_id
                )->first();
            if ($dup) {
                return response()->json([
                    'message'      => 'Duplicate contact (phone or email already exists). Link the existing one with contact_id.',
                    'duplicate_id' => $dup->id,
                ], 422);
            }

            $contact = Contact::create([
                'first_name'         => $data['first_name'],
                'last_name'          => $data['last_name'],
                'phone'              => $data['phone'],
                'email'              => $data['email'] ?? null,
                'id_number'          => $data['id_number'] ?? null,
                'contact_type_id'    => $data['contact_type_id'] ?? null,
                'notes'              => $data['notes'] ?? null,
                'created_by_user_id' => $user->id,
                'agency_id'          => $user->agency_id,
                'branch_id'          => $user->effectiveBranchId(),
            ]);
        }

        // Derive role from the contact type's esign_role when not given,
        // exactly like the web ContactPropertyController.
        if (empty($role)) {
            $esignRole = $contact->type?->esign_role;
            $role = ['seller' => 'owner', 'lessor' => 'lessor', 'buyer' => 'buyer', 'lessee' => 'tenant'][$esignRole] ?? null;
        }

        $property->contacts()->syncWithoutDetaching([$contact->id => ['role' => $role]]);

        if (in_array($role, ['owner', 'seller', 'landlord', 'lessor'], true)) {
            PropertySellerLink::ensureExists($property->id, $contact->id);
        }

        return response()->json([
            'message'    => 'Contact linked to property.',
            'contact'    => [
                'id'         => $contact->id,
                'full_name'  => trim(($contact->first_name ?? '') . ' ' . ($contact->last_name ?? '')),
                'phone'      => $contact->phone,
                'email'      => $contact->email,
                'role'       => $role,
            ],
        ], 201);
    }

    // ── DELETE /api/mobile/properties/{id}/contacts/{contact} ──────
    // Unlink a contact from the property (does NOT delete the contact).
    public function contactsUnlink(Request $request, Property $property, Contact $contact): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);

        $property->contacts()->detach($contact->id);

        return response()->json(['message' => 'Contact unlinked from property.']);
    }

    // ── POST /api/mobile/properties/{id}/gallery/tags ──────────────
    // Adds a custom gallery tag to the property. Body: { "tag": "Garden View" }.
    // Tag is trimmed, capitalised, max 40 chars. Case-insensitive de-dupe
    // against the property's already-available tags (derived + custom).
    // Returns the updated full available_tags list.
    public function addCustomTag(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);
        $data = $request->validate([
            'tag' => 'required|string|max:40',
        ]);

        $name = trim($data['tag']);
        if ($name === '') {
            return response()->json(['message' => 'Tag name is required.'], 422);
        }
        $name = mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);

        $current = $property->getAvailableGalleryTags();
        $existsLower = array_map('strtolower', $current);
        if (in_array(strtolower($name), $existsLower, true)) {
            return response()->json([
                'message'        => "Tag '{$name}' already exists for this property.",
                'available_tags' => $current,
            ], 200);
        }

        $custom = $property->gallery_custom_tags ?? [];
        $custom[] = $name;
        $property->update(['gallery_custom_tags' => array_values($custom)]);

        return response()->json([
            'message'        => "Tag '{$name}' added.",
            'available_tags' => $property->fresh()->getAvailableGalleryTags(),
        ]);
    }

    // ── DELETE /api/mobile/properties/{id}/gallery/tags ────────────
    // Removes a custom gallery tag. Body: { "tag": "Garden View" }.
    // Only custom tags can be removed — derived tags (from spaces) are
    // managed by editing spaces. Also strips the tag from any tagged
    // images so the gallery doesn't reference a dangling category.
    public function removeCustomTag(Request $request, Property $property): JsonResponse
    {
        $this->authorizeProperty($request->user(), $property);
        $data = $request->validate([
            'tag' => 'required|string|max:100',
        ]);
        $tag = trim($data['tag']);

        // Strip from custom_tags
        $custom  = $property->gallery_custom_tags ?? [];
        $remaining = array_values(array_filter($custom, fn($t) => strcasecmp($t, $tag) !== 0));

        // Move any images currently filed under this tag into the unsorted bucket.
        $cats = $property->gallery_categories_json ?? ['categories' => [], 'unsorted' => []];
        $unsorted = $cats['unsorted'] ?? [];
        $newCategories = [];
        foreach (($cats['categories'] ?? []) as $cat) {
            if (strcasecmp($cat['name'] ?? '', $tag) === 0) {
                $unsorted = array_merge($unsorted, $cat['images'] ?? []);
                continue;
            }
            $newCategories[] = $cat;
        }

        $property->update([
            'gallery_custom_tags'     => $remaining,
            'gallery_categories_json' => ['categories' => $newCategories, 'unsorted' => array_values(array_unique($unsorted))],
        ]);

        return response()->json([
            'message'        => "Tag '{$tag}' removed.",
            'available_tags' => $property->fresh()->getAvailableGalleryTags(),
        ]);
    }

    private function fullPropertyResponse(Property $property): array
    {
        // Absolute URLs so every image loads on a mobile device (relative
        // /storage paths only resolve in a same-origin browser).
        $galleryImages = $this->absoluteImageUrls($property->gallery_images_json ?? []);

        return [
            'id'              => $property->id,

            // Core
            'title'           => $property->title,
            'excerpt'         => $property->excerpt,
            'description'     => $property->description,
            'price'           => $property->price,
            'price_display'   => $property->formattedPrice(),

            // Address
            'address'         => $property->buildDisplayAddress(),
            'street_number'   => $property->street_number,
            'street_name'     => $property->street_name,
            'suburb'          => $property->suburb,
            'city'            => $property->city,
            'province'        => $property->province,

            // P24 chain IDs — let the mobile edit form pre-select the
            // Province → City → Suburb pickers without a name lookup.
            'p24_province_id' => $property->p24_province_id,
            'p24_city_id'     => $property->p24_city_id,
            'p24_suburb_id'   => $property->p24_suburb_id,
            'p24_suburb_mismatch' => (bool) $property->p24_suburb_mismatch,

            'region'          => $property->region,
            'district'        => $property->district,
            'complex_name'    => $property->complex_name,
            'unit_number'     => $property->unit_number,

            // Counts & sizes
            'beds'            => $property->beds,
            'baths'           => $property->baths,
            'garages'         => $property->garages,
            'size_m2'         => $property->size_m2,
            'erf_size_m2'     => $property->erf_size_m2,

            // Classification
            'status'          => $property->status,
            'property_type'   => $property->property_type,
            'category'        => $property->category,
            'listing_type'    => $property->listing_type,
            'mandate_type'    => $property->mandate_type,

            // Rental block (always present so the mobile edit form can
            // bind even if the property is currently a sale listing).
            // (float) is a defensive guarantee that the JSON stays numeric for
            // the mobile client regardless of the model's money cast.
            'rental_amount'    => $property->rental_amount !== null ? (float) $property->rental_amount : null,
            'deposit_amount'   => $property->deposit_amount !== null ? (float) $property->deposit_amount : null,
            'lease_start_date' => $property->lease_start_date?->toDateString(),
            'lease_end_date'   => $property->lease_end_date?->toDateString(),

            // Commission / fees
            'commission_percent' => $property->commission_percent,
            'admin_fee'          => $property->admin_fee !== null ? (float) $property->admin_fee : null,
            'marketing_fee'      => $property->marketing_fee !== null ? (float) $property->marketing_fee : null,

            // Features, spaces, gallery
            'features'        => $property->features_json ?? [],
            'spaces_json'     => $this->normalizeSpacesPayload($property->spaces_json ?? []),
            'gallery_images'  => $galleryImages,
            'gallery_categories' => $this->buildGalleryCategories($property),
            'gallery_tags'    => $property->getAvailableGalleryTags(),
            // Same first image as the web listing card (allImages()[0]), absolute.
            'thumbnail'       => $this->coverImageUrl($property),

            // Rental inspection galleries (in/out/custom) are a rental-only,
            // live-only feature. This flag is the single signal the mobile app
            // uses to decide whether to render the "Inspections" tab; the
            // dedicated endpoints under /rental-images enforce the same gate
            // server-side. Spec: .ai/specs/rental-images.md
            'rental_inspections_available' => $property->rentalInspectionsAvailable(),

            // Audit
            'agent_id'        => $property->agent_id,
            'agent_name'      => $property->agent?->name,
            'published_at'    => $property->published_at?->toIso8601String(),
            'updated_at'      => $property->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Transform the internal gallery_categories_json (array of {name, images})
     * into the mobile-friendly format: { "categories": { "Kitchen": [...], "Lounge": [...] } }
     */
    private function buildGalleryCategories(Property $property): array
    {
        $raw = $property->gallery_categories_json ?? ['categories' => [], 'unsorted' => []];
        $mapped = [];

        foreach ($raw['categories'] ?? [] as $cat) {
            $mapped[$cat['name']] = $this->absoluteImageUrls($cat['images'] ?? []);
        }

        return ['categories' => (object) $mapped];
    }

    // ── Image URL helpers ───────────────────────────────────────
    // The web renders images same-origin, so stored values may be relative
    // (/storage/…). A mobile device can't resolve those, so every image the
    // mobile API returns is absolutised against APP_URL. Already-absolute
    // URLs pass through untouched (idempotent).

    /** The property's cover image — same first image the web card shows. */
    private function coverImageUrl(Property $property): ?string
    {
        return $this->absoluteImageUrl($property->allImages()[0] ?? null);
    }

    /** Absolutise a single stored image URL/path. Null-safe. */
    private function absoluteImageUrl(?string $url): ?string
    {
        $url = trim((string) ($url ?? ''));
        if ($url === '') {
            return null;
        }
        // Already absolute (http/https or protocol-relative) — leave as-is.
        if (preg_match('#^(https?:)?//#i', $url)) {
            return $url;
        }
        return rtrim((string) config('app.url'), '/') . '/' . ltrim($url, '/');
    }

    /** Absolutise a list of stored image URLs/paths, dropping empties. */
    private function absoluteImageUrls(array $urls): array
    {
        return array_values(array_filter(array_map(
            fn ($u) => $this->absoluteImageUrl(is_string($u) ? $u : null),
            $urls
        )));
    }

    // ── Authorization ───────────────────────────────────────────
    // Delegates to the shared trait gate (ResolvesMobileDataScope) so the
    // single-record property scope check lives in exactly one place across the
    // mobile API.
    private function authorizeProperty(User $user, Property $property): void
    {
        $this->authorizePropertyAccess($user, $property);
    }
}
