<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\RentalCatalogueUnit;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Pastel-style enhancement, 2026-10-05 — the catalogue UNIT list (each,
 * dozen, box, ... + whatever an agency adds), managed on the same Company
 * Settings surface as VAT Types / Catalogue Types and linked from the
 * catalogue screen. Full CRUD (add/rename/reorder/archive/restore), no
 * hard delete.
 */
class RentalCatalogueUnitController extends Controller
{
    public function store(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $data = $this->validated($request);

        $unit = RentalCatalogueUnit::create($data + [
            'agency_id' => $agency->id,
            'sort_order' => (int) (RentalCatalogueUnit::where('agency_id', $agency->id)->max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', "Unit '{$unit->name}' added.");
    }

    public function update(Request $request, Agency $agency, RentalCatalogueUnit $catalogueUnit): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($catalogueUnit->agency_id === $agency->id, 404);

        $catalogueUnit->update($this->validated($request));

        return back()->with('success', "Unit '{$catalogueUnit->name}' updated.");
    }

    public function archive(Request $request, Agency $agency, RentalCatalogueUnit $catalogueUnit): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($catalogueUnit->agency_id === $agency->id, 404);

        $catalogueUnit->archive();

        return back()->with('success', "Unit '{$catalogueUnit->name}' archived.");
    }

    public function restore(Request $request, Agency $agency, int $catalogueUnit): RedirectResponse
    {
        $this->authorizeAgency($agency);

        $unit = RentalCatalogueUnit::withoutGlobalScope(AgencyScope::class)
            ->onlyTrashed()->where('agency_id', $agency->id)->findOrFail($catalogueUnit);
        $unit->restoreRecord();

        return back()->with('success', "Unit '{$unit->name}' restored.");
    }

    public function reorder(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);

        $validated = $request->validate([
            'order' => ['required', 'array'],
            'order.*' => ['integer'],
        ]);

        DB::transaction(function () use ($validated, $agency) {
            foreach ($validated['order'] as $position => $id) {
                RentalCatalogueUnit::where('id', $id)->where('agency_id', $agency->id)->update(['sort_order' => $position]);
            }
        });

        return back()->with('success', 'Units reordered.');
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'],
            'is_active' => ['nullable', 'boolean'],
        ]);
        $data['is_active'] = $request->boolean('is_active', true);

        return $data;
    }

    private function authorizeAgency(Agency $agency): void
    {
        $user = auth()->user();
        if ($user->isOwnerRole()) {
            return;
        }
        if ((int) $user->effectiveAgencyId() !== (int) $agency->id) {
            abort(403, 'You can only edit your own agency.');
        }
    }
}
