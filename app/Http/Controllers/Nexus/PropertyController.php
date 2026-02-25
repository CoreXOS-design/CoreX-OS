<?php

namespace App\Http\Controllers\Nexus;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Property;
use Illuminate\Http\Request;

class PropertyController extends Controller
{
    public function index()
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $query = Property::with(['agent', 'branch'])
            ->withTrashed(false);

        // Agents only see their own listings
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
        return view('nexus.properties.create-edit', [
            'property' => null,
            'branches' => $branches,
        ]);
    }

    public function store(Request $request)
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();

        $data = $request->validate([
            'title'         => 'required|string|max:200',
            'description'   => 'nullable|string',
            'price'         => 'required|integer|min:0',
            'suburb'        => 'required|string|max:100',
            'region'        => 'nullable|string|max:100',
            'beds'          => 'required|integer|min:0|max:20',
            'baths'         => 'required|integer|min:0|max:20',
            'garages'       => 'required|integer|min:0|max:20',
            'size_m2'       => 'nullable|integer|min:0',
            'erf_size_m2'   => 'nullable|integer|min:0',
            'property_type' => 'required|string|max:50',
            'mandate_type'  => 'nullable|string|max:50',
            'status'        => 'required|in:draft,active,sold,withdrawn',
            'branch_id'     => 'nullable|exists:branches,id',
            'publish'       => 'nullable|boolean',
        ]);

        $data['agent_id']  = $user->id;
        $data['agency_id'] = $user->effectiveAgencyId();

        if (! empty($data['publish'])) {
            $data['published_at'] = now();
            $data['status']       = 'active';
        }
        unset($data['publish']);

        Property::create($data);

        return redirect()->route('nexus.properties.index')
            ->with('success', 'Property listing created.');
    }

    public function edit(Property $property)
    {
        $this->authorizeProperty($property);
        $branches = Branch::orderBy('name')->get();
        return view('nexus.properties.create-edit', compact('property', 'branches'));
    }

    public function update(Request $request, Property $property)
    {
        $this->authorizeProperty($property);

        $data = $request->validate([
            'title'         => 'required|string|max:200',
            'description'   => 'nullable|string',
            'price'         => 'required|integer|min:0',
            'suburb'        => 'required|string|max:100',
            'region'        => 'nullable|string|max:100',
            'beds'          => 'required|integer|min:0|max:20',
            'baths'         => 'required|integer|min:0|max:20',
            'garages'       => 'required|integer|min:0|max:20',
            'size_m2'       => 'nullable|integer|min:0',
            'erf_size_m2'   => 'nullable|integer|min:0',
            'property_type' => 'required|string|max:50',
            'mandate_type'  => 'nullable|string|max:50',
            'status'        => 'required|in:draft,active,sold,withdrawn',
            'branch_id'     => 'nullable|exists:branches,id',
            'publish'       => 'nullable|boolean',
        ]);

        if (! empty($data['publish']) && ! $property->isPublished()) {
            $data['published_at'] = now();
            $data['status']       = 'active';
        }
        unset($data['publish']);

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

    private function authorizeProperty(Property $property): void
    {
        /** @var \App\Models\User $user */
        $user = auth()->user();
        $role = $user->effectiveRole();

        if (in_array($role, ['super_admin', 'admin'])) {
            return;
        }

        if ($role === 'branch_manager' && (int) $property->branch_id === (int) $user->effectiveBranchId()) {
            return;
        }

        if ((int) $property->agent_id === (int) $user->id) {
            return;
        }

        abort(403);
    }
}
