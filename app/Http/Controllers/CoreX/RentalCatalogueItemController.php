<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PerformanceSetting;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use App\Services\Rentals\RentalJobCardVatService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AT-442 — the agency's own parts & labour catalogue consumed by internal
 * job cards (rental-work-orders.md §14). Full CRUD (create/edit/archive/
 * restore), search/sort/filter/pagination per BUILD_STANDARD §1b,
 * agency-scoped via BelongsToAgency + AgencyScope on the model. Mirrors
 * RentalFaultTypeController's own shape.
 *
 * Pastel-style enhancement, 2026-10-05 — type/unit now pick from the
 * agency's own configurable lists (RentalCatalogueItemType/
 * RentalCatalogueUnit, managed on Company Settings — see
 * Admin\RentalCatalogueItemTypeController/RentalCatalogueUnitController);
 * default price is always stored excl-VAT and captured/shown per the
 * agency's VAT capture mode + the item's own default VAT type
 * (RentalJobCardVatService::catalogueItemPrices()).
 */
class RentalCatalogueItemController extends Controller
{
    public function __construct(private RentalJobCardVatService $vat)
    {
    }

    public function index(Request $request): View
    {
        $query = RentalCatalogueItem::query()->with(['catalogueItemType', 'catalogueUnit', 'defaultVatType']);

        if ($search = trim((string) $request->get('q', ''))) {
            $query->where(fn ($q) => $q->where('code', 'like', "%{$search}%")->orWhere('description', 'like', "%{$search}%"));
        }

        if ($typeId = $request->get('type')) {
            $query->where('rental_catalogue_item_type_id', $typeId);
        }

        $status = $request->get('status', 'active');
        if ($status === 'archived') {
            $query->onlyTrashed();
        } else {
            $query->where('is_active', true);
        }

        $sort = $request->get('sort', 'sort_order');
        $allowedSorts = ['sort_order', 'code', 'description', 'default_price'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'sort_order';
        }
        $query->orderBy($sort)->orderBy('id');

        $items = $query->paginate(25)->withQueryString();

        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        $priceLabel = $this->priceLabel($agency);
        $catalogueItemTypes = $agency ? RentalCatalogueItemType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get() : collect();

        return view('corex.rental-catalogue-items.index', compact('items', 'status', 'sort', 'priceLabel', 'catalogueItemTypes') + ['vat' => $this->vat, 'agency' => $agency]);
    }

    public function create(Request $request): View
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());

        return view('corex.rental-catalogue-items.create', $this->formData($agency) + ['canViewCosts' => (bool) $request->user()->hasPermission('rental_job_cards.view_costs')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        $data = $this->validated($request, $agency);

        $item = RentalCatalogueItem::create($data + [
            'sort_order' => (int) (RentalCatalogueItem::max('sort_order') ?? 0) + 1,
            'created_by_user_id' => $request->user()->id,
        ]);

        return redirect()->route('corex.rental-catalogue-items.index')->with('success', "'{$item->code} — {$item->description}' added to the catalogue.");
    }

    public function edit(Request $request, RentalCatalogueItem $rentalCatalogueItem): View
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());

        $canViewCosts = (bool) $request->user()->hasPermission('rental_job_cards.view_costs');

        return view('corex.rental-catalogue-items.edit', ['item' => $rentalCatalogueItem] + $this->formData($agency) + [
            'canViewCosts' => $canViewCosts,
            // Stored excl VAT; shown in the agency's capture mode, like the price.
            'itemCost' => ($canViewCosts && $agency) ? $this->vat->catalogueDefaultCostForLine($rentalCatalogueItem, $agency) : null,
            // The price box edits the figure in the agency's capture basis (incl or excl), NOT the stored excl amount —
            // otherwise an incl-VAT agency re-saves the excl figure as if it were incl and the price drops on every Save.
            'itemPrice' => $agency ? $this->vat->catalogueDefaultPriceForLine($rentalCatalogueItem, $agency) : null,
        ]);
    }

    public function update(Request $request, RentalCatalogueItem $rentalCatalogueItem): RedirectResponse
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        $rentalCatalogueItem->update($this->validated($request, $agency));

        return redirect()->route('corex.rental-catalogue-items.index')->with('success', "'{$rentalCatalogueItem->code}' updated.");
    }

    public function archive(RentalCatalogueItem $rentalCatalogueItem): RedirectResponse
    {
        $rentalCatalogueItem->archive();

        return back()->with('success', "'{$rentalCatalogueItem->code}' archived.");
    }

    public function restore(int $id): RedirectResponse
    {
        $item = RentalCatalogueItem::onlyTrashed()->findOrFail($id);
        $item->restoreRecord();

        return back()->with('success', "'{$item->code}' restored.");
    }

    /** Shared create/edit view data — the agency's own type/unit/VAT-type lists, plus a JSON-ready VAT-type map for the live excl/incl calc in Alpine. */
    private function formData(?Agency $agency): array
    {
        $catalogueItemTypes = $agency ? RentalCatalogueItemType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get() : collect();
        $catalogueUnits = $agency ? RentalCatalogueUnit::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get() : collect();
        $vatTypes = $agency?->vat_registered ? RentalVatType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get() : collect();

        $vatTypesForJs = $vatTypes->map(fn (RentalVatType $t) => [
            'id' => $t->id, 'name' => $t->name, 'rate_mode' => $t->rate_mode, 'fixed_rate' => (float) ($t->fixed_rate ?? 0),
        ])->values();

        return [
            'priceLabel' => $this->priceLabel($agency),
            'costLabel' => $this->costLabel($agency),
            'catalogueItemTypes' => $catalogueItemTypes,
            'catalogueUnits' => $catalogueUnits,
            'vatTypes' => $vatTypes,
            'vatTypesForJs' => $vatTypesForJs,
            'agency' => $agency,
            'agencyVatRate' => (float) PerformanceSetting::get('vat_rate', 15, $agency?->id),
        ];
    }

    private function validated(Request $request, ?Agency $agency): array
    {
        $agencyId = $agency?->id;
        $currentId = $request->route('rentalCatalogueItem')?->id;

        $data = $request->validate([
            'rental_catalogue_item_type_id' => ['required', Rule::exists('rental_catalogue_item_types', 'id')->where('agency_id', $agencyId)],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('rental_catalogue_items', 'code')
                    ->where('agency_id', $agencyId)
                    ->whereNull('deleted_at')
                    ->ignore($currentId),
            ],
            'description' => ['required', 'string', 'max:500'],
            'rental_catalogue_unit_id' => ['required', Rule::exists('rental_catalogue_units', 'id')->where('agency_id', $agencyId)],
            'default_price' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            // §17.4.4 — what the item usually COSTS the agency; prefills the cost on a new job card line. Needs `view_costs` to set.
            'default_cost' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'default_rental_vat_type_id' => ['nullable', Rule::exists('rental_vat_types', 'id')->where('agency_id', $agencyId)],
            'default_custom_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]) + ['is_active' => $request->boolean('is_active', true), 'default_price' => null, 'default_cost' => null];
        // The trailing 'default_price' => null above is a DEFAULT, not an
        // override — PHP's array union (+) keeps the validator's own key
        // when present and only fills the gap when the field was omitted
        // entirely from the request. A bare 'nullable' rule with no key in
        // the input leaves the key missing from validate()'s own return
        // array, not merely set to null — caught live: the lazy-but-valid
        // "no price typed" path 500'd on an undefined array key before
        // this fix (the input-space rule, BUILD_STANDARD §2).

        // "Store unambiguously (excl amount + vat type)" (Johan) — when the
        // agency captures prices incl-VAT, what was typed/shown as the
        // editable amount is the INCL figure; convert it down to excl
        // before it ever reaches the DB. No-op when not registered, no VAT
        // type picked, or the resolved rate is 0 (excl === incl already).
        if (($data['default_price'] !== null || $data['default_cost'] !== null) && $agency?->vat_registered && $agency->vat_capture_mode === Agency::VAT_CAPTURE_INCL) {
            $type = !empty($data['default_rental_vat_type_id']) ? RentalVatType::find($data['default_rental_vat_type_id']) : null;
            $rate = $type
                ? ($type->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE
                    ? (float) ($data['default_custom_vat_rate'] ?? 0)
                    : (float) $type->liveRate())
                : 0.0;
            if ($rate > 0) {
                // Cost is held on the same VAT basis as price (§17.19): typed incl, stored excl.
                foreach (['default_price', 'default_cost'] as $field) {
                    if ($data[$field] !== null) {
                        $data[$field] = $this->vat->splitAmount((float) $data[$field], $rate, Agency::VAT_CAPTURE_INCL)['excl'];
                    }
                }
            }
        }

        // §17.15 — a user who cannot see costs cannot set (or wipe) one: the key is dropped, so an update leaves the stored cost alone.
        if (! $request->user()?->hasPermission('rental_job_cards.view_costs')) {
            unset($data['default_cost']);
        }

        if (empty($data['default_rental_vat_type_id'])) {
            $data['default_custom_vat_rate'] = null;
        }

        return $data;
    }

    /** The default-cost field's label follows the same VAT set-up as the price (§17.19). */
    private function costLabel(?Agency $agency): string
    {
        if (! $agency?->vat_registered) {
            return 'Default cost (R, optional)';
        }

        return $agency->vat_capture_mode === Agency::VAT_CAPTURE_INCL ? 'Default cost (incl VAT) (R, optional)' : 'Default cost (excl VAT) (R, optional)';
    }

    /** The default-price field's label follows the agency's VAT set-up — no data conversion implied by the label alone. */
    private function priceLabel(?Agency $agency): string
    {
        if (! $agency?->vat_registered) {
            return 'Price';
        }

        return $agency->vat_capture_mode === Agency::VAT_CAPTURE_INCL ? 'Price (incl VAT)' : 'Price (excl VAT)';
    }
}
