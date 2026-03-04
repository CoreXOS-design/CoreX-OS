<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\P24Suburb;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class P24SuburbController extends Controller
{
    private function ensureAccess(): void
    {
        abort_unless(Auth::user()?->hasPermission('manage_p24'), 403);
    }

    public function index()
    {
        $this->ensureAccess();

        $suburbs = P24Suburb::orderBy('name')->get();

        return view('admin.p24-suburbs', compact('suburbs'));
    }

    public function store(Request $request)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'region'          => 'nullable|string|max:100',
            'surrounding_ids' => 'nullable|string|max:500',
            'confirmed'       => 'nullable|boolean',
        ]);

        $slug = strtolower(str_replace(' ', '-', trim($validated['name'])));

        // Parse surrounding IDs from comma-separated string
        $surroundingIds = [];
        if (!empty($validated['surrounding_ids'])) {
            $surroundingIds = array_map('intval', array_filter(
                explode(',', $validated['surrounding_ids']),
                fn($v) => trim($v) !== ''
            ));
        }

        P24Suburb::create([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => $validated['region'] ?? 'kzn-south-coast',
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ]);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb added successfully.');
    }

    public function update(Request $request, P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'p24_id'          => 'nullable|integer|min:1',
            'region'          => 'nullable|string|max:100',
            'surrounding_ids' => 'nullable|string|max:500',
            'confirmed'       => 'nullable|boolean',
        ]);

        $slug = strtolower(str_replace(' ', '-', trim($validated['name'])));

        $surroundingIds = [];
        if (!empty($validated['surrounding_ids'])) {
            $surroundingIds = array_map('intval', array_filter(
                explode(',', $validated['surrounding_ids']),
                fn($v) => trim($v) !== ''
            ));
        }

        $p24Suburb->update([
            'name'            => trim($validated['name']),
            'slug'            => $slug,
            'p24_id'          => $validated['p24_id'] ?? null,
            'region'          => $validated['region'] ?? 'kzn-south-coast',
            'surrounding_ids' => $surroundingIds,
            'confirmed'       => !empty($validated['confirmed']),
        ]);

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb updated.');
    }

    public function destroy(P24Suburb $p24Suburb)
    {
        $this->ensureAccess();

        $p24Suburb->delete();

        return redirect()->route('admin.p24-suburbs.index')
            ->with('success', 'Suburb deleted.');
    }
}
