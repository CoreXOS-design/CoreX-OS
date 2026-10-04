<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\RentalCatalogueItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-442 — the agency's own parts & labour catalogue consumed by internal
 * job cards (rental-work-orders.md §14). Full CRUD (create/edit/archive/
 * restore), search/sort/filter/pagination per BUILD_STANDARD §1b,
 * agency-scoped via BelongsToAgency + AgencyScope on the model. Mirrors
 * RentalFaultTypeController's own shape.
 */
class RentalCatalogueItemController extends Controller
{
    public function index(Request $request): View
    {
        $query = RentalCatalogueItem::query();

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where('name', 'like', "%{$search}%");
        }

        if ($type = $request->get('type')) {
            $query->where('type', $type);
        }

        $status = $request->get('status', 'active');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } else {
            $query->where('is_active', true);
        }

        $sort = $request->get('sort', 'sort_order');
        $allowedSorts = ['sort_order', 'name', 'type', 'default_price'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'sort_order';
        }
        $query->orderBy($sort)->orderBy('id');

        $items = $query->paginate(25)->withQueryString();

        return view('corex.rental-catalogue-items.index', compact('items', 'status', 'sort'));
    }

    public function create(): View
    {
        return view('corex.rental-catalogue-items.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        $item = RentalCatalogueItem::create($data + [
            'sort_order' => (int) (RentalCatalogueItem::max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        return redirect()->route('corex.rental-catalogue-items.index')->with('success', "'{$item->name}' added to the catalogue.");
    }

    public function edit(RentalCatalogueItem $rentalCatalogueItem): View
    {
        return view('corex.rental-catalogue-items.edit', ['item' => $rentalCatalogueItem]);
    }

    public function update(Request $request, RentalCatalogueItem $rentalCatalogueItem): RedirectResponse
    {
        $rentalCatalogueItem->update($this->validated($request));

        return redirect()->route('corex.rental-catalogue-items.index')->with('success', "'{$rentalCatalogueItem->name}' updated.");
    }

    public function archive(RentalCatalogueItem $rentalCatalogueItem): RedirectResponse
    {
        $rentalCatalogueItem->archive();

        return back()->with('success', "'{$rentalCatalogueItem->name}' archived.");
    }

    public function restore(int $id): RedirectResponse
    {
        $item = RentalCatalogueItem::onlyTrashed()->findOrFail($id);
        $item->restoreRecord();

        return back()->with('success', "'{$item->name}' restored.");
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:' . implode(',', [RentalCatalogueItem::TYPE_LABOUR, RentalCatalogueItem::TYPE_PART])],
            'name' => ['required', 'string', 'max:191'],
            'unit' => ['required', 'string', 'max:30'],
            'default_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active', true)];
    }
}
