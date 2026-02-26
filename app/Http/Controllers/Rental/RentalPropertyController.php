<?php

namespace App\Http\Controllers\Rental;

use App\Http\Controllers\Controller;
use App\Models\Rental\RentalProperty;
use Illuminate\Http\Request;

class RentalPropertyController extends Controller
{
    public function index()
    {
        $properties = RentalProperty::orderBy('full_address')
            ->get()
            ->groupBy(fn ($p) => $p->is_active ? 1 : 0);

        $active = $properties->get(1, collect());
        $inactive = $properties->get(0, collect());

        return view('rental.settings.properties.index', compact('active', 'inactive'));
    }

    public function create()
    {
        return view('rental.settings.properties.create');
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'suburb' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'province' => 'nullable|string|max:255',
            'property_type' => 'nullable|string',
            'landlord_name' => 'nullable|string|max:255',
            'landlord_email' => 'nullable|email|max:255',
            'landlord_phone' => 'nullable|string|max:20',
            'monthly_rental' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
        ]);

        $validated['created_by'] = auth()->id();

        RentalProperty::create($validated);

        return redirect()->route('rental.settings.properties.index')
            ->with('success', 'Property added successfully.');
    }

    public function edit(RentalProperty $property)
    {
        return view('rental.settings.properties.edit', compact('property'));
    }

    public function update(Request $request, RentalProperty $property)
    {
        $validated = $request->validate([
            'address_line_1' => 'required|string|max:255',
            'address_line_2' => 'nullable|string|max:255',
            'suburb' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:10',
            'province' => 'nullable|string|max:255',
            'property_type' => 'nullable|string',
            'landlord_name' => 'nullable|string|max:255',
            'landlord_email' => 'nullable|email|max:255',
            'landlord_phone' => 'nullable|string|max:20',
            'monthly_rental' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string',
            'is_active' => 'boolean',
        ]);

        $property->update($validated);

        return redirect()->route('rental.settings.properties.index')
            ->with('success', 'Property updated.');
    }

    public function toggleActive(RentalProperty $property)
    {
        $property->update(['is_active' => !$property->is_active]);

        $status = $property->is_active ? 'activated' : 'deactivated';
        return back()->with('success', "Property {$status}.");
    }

    public function search(Request $request)
    {
        $query = $request->input('q', '');

        $properties = RentalProperty::active()
            ->where('full_address', 'LIKE', "%{$query}%")
            ->orderBy('full_address')
            ->limit(20)
            ->get(['id', 'full_address', 'landlord_name', 'property_type']);

        return response()->json($properties);
    }
}
