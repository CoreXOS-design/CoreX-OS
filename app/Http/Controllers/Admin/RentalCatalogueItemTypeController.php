<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\RentalCatalogueItemType;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Pastel-style enhancement, 2026-10-05 — the catalogue item TYPE list
 * (Labour/Part + whatever an agency adds), managed on the same Company
 * Settings surface as VAT Types (RentalVatTypeController, which this
 * mirrors exactly) and linked from the catalogue screen. Full CRUD
 * (add/rename/reorder/archive/restore), no hard delete.
 */
class RentalCatalogueItemTypeController extends Controller
{
    public function store(Request $request, Agency $agency): RedirectResponse
    {
        $this->authorizeAgency($agency);
        $data = $this->validated($request, $agency);

        $type = RentalCatalogueItemType::create($data + [
            'agency_id' => $agency->id,
            'sort_order' => (int) (RentalCatalogueItemType::where('agency_id', $agency->id)->max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', "Catalogue type '{$type->name}' added.");
    }

    public function update(Request $request, Agency $agency, RentalCatalogueItemType $catalogueItemType): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($catalogueItemType->agency_id === $agency->id, 404);

        $catalogueItemType->update($this->validated($request, $agency));

        return back()->with('success', "Catalogue type '{$catalogueItemType->name}' updated.");
    }

    public function archive(Request $request, Agency $agency, RentalCatalogueItemType $catalogueItemType): RedirectResponse
    {
        $this->authorizeAgency($agency);
        abort_unless($catalogueItemType->agency_id === $agency->id, 404);

        $catalogueItemType->archive();

        return back()->with('success', "Catalogue type '{$catalogueItemType->name}' archived.");
    }

    public function restore(Request $request, Agency $agency, int $catalogueItemType): RedirectResponse
    {
        $this->authorizeAgency($agency);

        $type = RentalCatalogueItemType::withoutGlobalScope(AgencyScope::class)
            ->onlyTrashed()->where('agency_id', $agency->id)->findOrFail($catalogueItemType);
        $type->restoreRecord();

        return back()->with('success', "Catalogue type '{$type->name}' restored.");
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
                RentalCatalogueItemType::where('id', $id)->where('agency_id', $agency->id)->update(['sort_order' => $position]);
            }
        });

        return back()->with('success', 'Catalogue types reordered.');
    }

    private function validated(Request $request, Agency $agency): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'kind' => ['required', Rule::in(RentalCatalogueItemType::KINDS)],
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
