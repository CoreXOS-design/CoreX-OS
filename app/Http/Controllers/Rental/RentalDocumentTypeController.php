<?php

namespace App\Http\Controllers\Rental;

use App\Http\Controllers\Controller;
use App\Models\Rental\RentalDocumentType;
use Illuminate\Http\Request;

class RentalDocumentTypeController extends Controller
{
    public function index()
    {
        $types = RentalDocumentType::orderBy('sort_order')->get();

        return view('rental.settings.document-types.index', compact('types'));
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
            'is_lease' => 'boolean',
        ]);

        $maxOrder = RentalDocumentType::max('sort_order') ?? 0;

        RentalDocumentType::create([
            'name' => $request->name,
            'color' => $request->color ?? '#6B7280',
            'is_lease' => $request->boolean('is_lease'),
            'sort_order' => $maxOrder + 1,
        ]);

        return back()->with('success', 'Document type added.');
    }

    public function update(Request $request, RentalDocumentType $type)
    {
        $request->validate([
            'name' => 'required|string|max:100',
            'color' => 'nullable|string|max:7',
            'is_lease' => 'boolean',
        ]);

        $type->update([
            'name' => $request->name,
            'color' => $request->color ?? $type->color,
            'is_lease' => $request->boolean('is_lease'),
        ]);

        return back()->with('success', 'Document type updated.');
    }

    public function toggleActive(RentalDocumentType $type)
    {
        if ($type->is_system) {
            return back()->with('error', 'System document types cannot be deactivated.');
        }

        $type->update(['is_active' => !$type->is_active]);
        return back()->with('success', $type->is_active ? 'Type activated.' : 'Type deactivated.');
    }
}
