<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertySettingItem;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * 4-step wizard for creating a property.
 *
 * Design contract (DO NOT BREAK):
 *   - Uses Property::create() / $property->update() only.
 *   - PropertyObserver::saved() fires automatically and dispatches
 *     SyncPropertyToWebsite when published_at transitions.
 *   - Never touches pp_* or p24_* columns directly.
 *   - agency_id / agent_id / branch_id set via smart defaults on draft creation.
 */
class PropertyWizardController extends Controller
{
    use \App\Http\Concerns\AppliesP24Location;
    use \App\Http\Controllers\Concerns\AuthorizesPropertyAccess;

    public function start(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless($user->hasPermission('properties.create'), 403);

        // Resume an existing draft ONLY when explicitly asked (AT-188). The
        // "Drafts" button on the listing links here with ?resume; a plain
        // "New Property" visit carries no ?resume and always starts fresh, so
        // it no longer silently drops the user back onto their last draft.
        $draft = null;
        if ($request->has('resume')) {
            $draft = Property::query()
                ->where('agent_id', $user->id)
                ->where('status', 'draft')
                ->whereNull('published_at')
                ->latest('updated_at')
                ->first();
        }

        $settingItems = [
            'types'        => PropertySettingItem::group('property_type')->where('active', true)->get(),
            'mandateTypes' => PropertySettingItem::group('mandate_type')->get(),
        ];
        $branches = Branch::orderBy('name')->get();
        $agents   = $this->agentList($user);

        // Unique suburb list for autocomplete (agent's scope)
        $suburbs = Property::query()
            ->whereNotNull('suburb')
            ->distinct()
            ->orderBy('suburb')
            ->pluck('suburb')
            ->filter()
            ->values();

        // Pre-fill from contact when launched from a contact page (AT-60 parity
        // with the Classic form). The contact's STRUCTURED address seeds step 1
        // and the contact is linked to the draft on createDraft().
        $preLinkedContact = null;
        $contactPrefill   = null;
        if ($contactId = $request->query('contact_id')) {
            $contact = \App\Models\Contact::find($contactId);
            if ($contact) {
                $preLinkedContact = $contact;
                $contactPrefill = [
                    'contact_id'         => $contact->id,
                    'unit_number'        => $contact->unit_number,
                    'floor_number'       => $contact->floor_number,
                    'unit_section_block' => $contact->unit_section_block,
                    'complex_name'       => $contact->complex_name,
                    'street_number'      => $contact->street_number,
                    'street_name'        => $contact->street_name,
                    'suburb'             => $contact->suburb,
                    'city'               => $contact->city,
                    'province'           => $contact->province,
                    'p24_province_id'    => (int) ($contact->p24_province_id ?? 0),
                    'p24_city_id'        => (int) ($contact->p24_city_id ?? 0),
                    'p24_suburb_id'      => (int) ($contact->p24_suburb_id ?? 0),
                ];
            }
        }

        return view('corex.properties.wizard', compact(
            'draft', 'settingItems', 'branches', 'agents', 'suburbs',
            'preLinkedContact', 'contactPrefill'
        ));
    }

    /** STEP 1 — create the draft property with smart defaults. */
    public function createDraft(Request $request)
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless($user->hasPermission('properties.create'), 403);

        $data = $request->validate([
            'listing_type'    => 'required|string|in:sale,rental',
            'property_type'   => 'required|string|max:50',
            'suburb'          => 'nullable|string|max:100',
            'city'            => 'nullable|string|max:100',
            'province'        => 'nullable|string|max:100',
            'p24_province_id' => 'required|integer|exists:p24_provinces,id',
            'p24_city_id'     => 'required|integer|exists:p24_cities,id',
            'p24_suburb_id'   => 'required|integer|exists:p24_suburbs,id',
            'street_number'   => 'nullable|string|max:50',
            'street_name'     => 'nullable|string|max:255',
            'unit_number'         => 'nullable|string|max:50',
            'floor_number'        => 'nullable|string|max:50',
            'unit_section_block'  => 'nullable|string|max:255',
            'complex_name'        => 'nullable|string|max:255',
            'property_number'     => 'nullable|string|max:100',
            'stand_number'        => 'nullable|string|max:100',
            'zone_type'           => 'nullable|string|max:50',
            'district'            => 'nullable|string|max:255',
            'region'              => 'nullable|string|max:255',
            'address_internal_note' => 'nullable|string|max:5000',
            'price'           => 'required|integer|min:0',
            'beds'            => 'required|integer|min:0|max:20',
            'baths'           => 'required|integer|min:0|max:20',
            'half_baths'      => 'nullable|integer|min:0|max:20',
            'garages'         => 'required|integer|min:0|max:20',
            'title'           => 'required|string|max:200',
            'contact_id'      => 'nullable|integer|exists:contacts,id',
        ]);

        $contactId = $data['contact_id'] ?? null;
        unset($data['contact_id']);

        // Verify P24 chain and overwrite text columns with canonical names.
        $data = $this->applyP24Location($data);

        // Smart defaults — Observer will set agency_id via BelongsToAgency
        $data['agent_id']  = $user->id;
        $data['branch_id'] = $user->effectiveBranchId();
        $data['status']    = 'draft';

        // Observer fires saved() — published_at is null so SyncPropertyToWebsite is NOT dispatched
        $property = Property::create($data);

        if ($property->p24_suburb_id) {
            event(new \App\Events\Property\PropertySuburbLinked(
                property: $property,
                previousP24SuburbId: null,
                newP24SuburbId: (int) $property->p24_suburb_id,
                actorUserId: $user->id,
            ));
        }

        // Link the originating contact as the seller side of the listing
        // (sale → seller, rental → landlord), mirroring the Classic form so the
        // contact never lands orphaned and the compliance gate sees a seller.
        if ($contactId) {
            $contact = \App\Models\Contact::find($contactId);
            if ($contact) {
                $role = ($property->listing_type ?? 'sale') === 'rental' ? 'landlord' : 'seller';
                $property->contacts()->syncWithoutDetaching([$contact->id => ['role' => $role]]);
                \App\Models\PropertySellerLink::ensureExists($property->id, $contact->id);
                event(new \App\Events\Contact\ContactLinkedToProperty(
                    contact: $contact,
                    property: $property,
                    role: $role,
                    actorUserId: $user->id,
                ));
            }
        }

        if ($request->wantsJson()) {
            return response()->json([
                'ok'       => true,
                'property' => ['id' => $property->id, 'title' => $property->title],
                'next'     => 'photos',
            ]);
        }
        return redirect()->route('corex.properties.wizard')->with('draft_id', $property->id);
    }

    /** STEP 2 — append photos to the draft. */
    public function uploadPhotos(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate([
            'gallery_images'   => 'required|array|min:1',
            'gallery_images.*' => 'image|max:5120',
        ]);

        $newUrls = [];
        foreach ($request->file('gallery_images', []) as $file) {
            $path      = $file->store("properties/{$property->id}", 'public');
            $newUrls[] = Storage::url($path);
        }

        $existing = $property->gallery_images_json ?? [];
        $property->update([
            'gallery_images_json' => array_values(array_merge($existing, $newUrls)),
        ]);

        if ($request->wantsJson()) {
            return response()->json([
                'ok'       => true,
                'uploaded' => count($newUrls),
                'urls'     => $newUrls,
                'total'    => count($property->gallery_images_json ?? []),
            ]);
        }
        return back()->with('success', count($newUrls) . ' photo(s) uploaded.');
    }

    /** Re-order photos (cover = index 0). */
    public function reorderPhotos(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate([
            'order'   => 'required|array',
            'order.*' => 'integer|min:0',
        ]);

        $old = $property->gallery_images_json ?? [];
        $new = [];
        foreach ($request->input('order') as $idx) {
            if (isset($old[(int) $idx])) $new[] = $old[(int) $idx];
        }
        $property->update(['gallery_images_json' => $new]);

        return response()->json(['ok' => true]);
    }

    /** Remove a queued photo (pre-finalize). */
    public function removePhoto(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate(['index' => 'required|integer|min:0']);
        $idx    = (int) $request->input('index');
        $images = $property->gallery_images_json ?? [];

        if (isset($images[$idx])) {
            $url  = $images[$idx];
            $path = str_replace('/storage/', '', parse_url($url, PHP_URL_PATH) ?? '');
            if ($path) Storage::disk('public')->delete($path);
            array_splice($images, $idx, 1);
            $property->update(['gallery_images_json' => $images]);
        }
        return response()->json(['ok' => true]);
    }

    /** STEP 3 — extended details (description, mandate, sizes, commission, etc.). */
    public function saveStep(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'description'        => 'nullable|string',
            'excerpt'            => 'nullable|string|max:500',
            'mandate_type'       => 'nullable|string|max:50',
            'branch_id'          => 'nullable|exists:branches,id',
            'agent_id'           => 'nullable|exists:users,id',
            'size_m2'            => 'nullable|integer|min:0',
            'erf_size_m2'        => 'nullable|integer|min:0',
            'rental_amount'      => 'nullable|numeric|min:0',
            'deposit_amount'     => 'nullable|numeric|min:0',
            'lease_start_date'   => 'nullable|date',
            'lease_end_date'     => 'nullable|date',
            'features'           => 'nullable|array',
            'features.*'         => 'string|max:100',
        ]);

        if (array_key_exists('features', $data)) {
            $data['features_json'] = array_values(array_filter($data['features']));
            unset($data['features']);
        }

        // Only admin/BM can reassign agent
        $scope = PermissionService::getDataScope(auth()->user(), 'properties');
        if (!in_array($scope, ['all', 'branch'])) {
            unset($data['agent_id']);
        }

        $property->update($data);

        if ($request->wantsJson()) {
            return response()->json(['ok' => true, 'next' => 'review']);
        }
        return back()->with('success', 'Details saved.');
    }

    /** STEP 4 — Save as draft (default) or Save & Publish. */
    public function finalize(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $request->validate(['publish' => 'nullable|boolean']);

        $publish = $request->boolean('publish');

        if ($publish) {
            // Defensive readiness gate — mirror client checklist
            $images = $property->gallery_images_json ?? [];
            abort_if(empty($property->title) || empty($property->price) || empty($property->suburb) || empty($images),
                422, 'Property is missing required fields for publishing.');

            // Observer sees published_at transition → dispatches SyncPropertyToWebsite
            $property->update([
                'published_at' => now(),
                'status'       => 'active',
            ]);
        }
        // else: stays draft — nothing to persist

        return redirect()->route('corex.properties.show', $property)
            ->with('success', $publish ? 'Property published.' : 'Property saved as draft.');
    }

    /** Discard the current draft (soft delete). */
    public function discardDraft(Property $property)
    {
        $this->authorizeProperty($property);
        abort_unless($property->status === 'draft' && is_null($property->published_at),
            422, 'Only unpublished drafts can be discarded.');
        $property->delete();
        return redirect()->route('corex.properties.index')->with('success', 'Draft discarded.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function agentList(User $user): \Illuminate\Support\Collection
    {
        $scope = PermissionService::getDataScope($user, 'properties');

        $query = User::agencyMembers()->orderBy('name')->where('is_active', 1);

        if ($scope === 'branch') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) $query->where('branch_id', $branchId);
        } elseif ($scope !== 'all') {
            $query->where('id', $user->id);
        }

        return $query->get(['id', 'name', 'email']);
    }

    // authorizeProperty() now lives in the AuthorizesPropertyAccess trait.
}
