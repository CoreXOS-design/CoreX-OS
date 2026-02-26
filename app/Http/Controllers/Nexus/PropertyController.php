<?php

namespace App\Http\Controllers\Nexus;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyController extends Controller
{
    public function index()
    {
        /** @var User $user */
        $user = auth()->user();

        $query = Property::with(['agent', 'branch'])->withTrashed(false);

        if ($user->effectiveRole() === 'agent') {
            $query->where('agent_id', $user->id);
        } elseif ($user->effectiveRole() === 'branch_manager') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        }

        $properties = $query->orderByDesc('created_at')->get();
        return view('nexus.properties.index', compact('properties'));
    }

    public function create()
    {
        $branches = Branch::orderBy('name')->get();
        $agents   = $this->agentList();
        return view('nexus.properties.create-edit', [
            'property' => null,
            'branches' => $branches,
            'agents'   => $agents,
        ]);
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
            'city'             => 'nullable|string|max:100',
            'suburb'           => 'required|string|max:100',
            'region'           => 'nullable|string|max:100',
            'beds'             => 'required|integer|min:0|max:20',
            'baths'            => 'required|integer|min:0|max:20',
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'required|string|max:50',
            'mandate_type'     => 'nullable|string|max:50',
            'status'           => 'required|in:draft,active,sold,withdrawn',
            'branch_id'        => 'nullable|exists:branches,id',
            'agent_id'         => 'nullable|exists:users,id',
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

        $role = $user->effectiveRole();
        if (! in_array($role, ['super_admin', 'admin', 'branch_manager']) || empty($data['agent_id'])) {
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

        return redirect()->route('nexus.properties.index')
            ->with('success', 'Property listing created.');
    }

    public function edit(Property $property)
    {
        $this->authorizeProperty($property);
        $branches = Branch::orderBy('name')->get();
        $agents   = $this->agentList();
        return view('nexus.properties.create-edit', compact('property', 'branches', 'agents'));
    }

    public function update(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'title'            => 'required|string|max:200',
            'excerpt'          => 'nullable|string|max:500',
            'description'      => 'nullable|string',
            'price'            => 'required|integer|min:0',
            'city'             => 'nullable|string|max:100',
            'suburb'           => 'required|string|max:100',
            'region'           => 'nullable|string|max:100',
            'beds'             => 'required|integer|min:0|max:20',
            'baths'            => 'required|integer|min:0|max:20',
            'garages'          => 'required|integer|min:0|max:20',
            'size_m2'          => 'nullable|integer|min:0',
            'erf_size_m2'      => 'nullable|integer|min:0',
            'property_type'    => 'required|string|max:50',
            'mandate_type'     => 'nullable|string|max:50',
            'status'           => 'required|in:draft,active,sold,withdrawn',
            'branch_id'        => 'nullable|exists:branches,id',
            'agent_id'         => 'nullable|exists:users,id',
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

        return redirect()->route('nexus.properties.index')
            ->with('success', 'Property listing updated.');
    }

    public function destroy(Property $property)
    {
        $this->authorizeProperty($property);
        $property->delete();
        return redirect()->route('nexus.properties.index')
            ->with('success', 'Property listing removed.');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private function storeImages(Request $request, string $field, int $propertyId): array
    {
        $urls = [];
        if ($request->hasFile($field)) {
            foreach ($request->file($field) as $file) {
                $path   = $file->store("properties/{$propertyId}", 'public');
                $urls[] = url(Storage::url($path));
            }
        }
        return $urls;
    }

    private function agentList(): \Illuminate\Support\Collection
    {
        /** @var User $user */
        $user = auth()->user();
        $role = $user->effectiveRole();

        $query = User::orderBy('name')->where('is_active', 1);

        if ($role === 'branch_manager') {
            $branchId = $user->effectiveBranchId();
            if ($branchId) {
                $query->where('branch_id', $branchId);
            }
        } elseif (! in_array($role, ['super_admin', 'admin'])) {
            $query->where('id', $user->id);
        }

        return $query->get(['id', 'name', 'email']);
    }

    private function authorizeProperty(Property $property): void
    {
        /** @var User $user */
        $user = auth()->user();
        $role = $user->effectiveRole();

        if (in_array($role, ['super_admin', 'admin'])) return;
        if ($role === 'branch_manager' && (int) $property->branch_id === (int) $user->effectiveBranchId()) return;
        if ((int) $property->agent_id === (int) $user->id) return;

        abort(403);
    }
}
