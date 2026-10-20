<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Models\PerformanceSetting;
use App\Models\RentalCatalogueItem;
use App\Models\RentalCatalogueItemType;
use App\Models\RentalCatalogueUnit;
use App\Models\RentalVatType;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\CatalogueItemPricing;
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
 *
 * §17.38 — the price + default-cost boxes became one Cost / Markup / Selling block (cost_excl/cost_incl, markup_type/
 * markup_value, selling_excl/selling_incl); the maths is CatalogueItemPricing's, shared with the screen's own JS.
 */
class RentalCatalogueItemController extends Controller
{
    public function __construct(private RentalJobCardVatService $vat, private CatalogueItemPricing $pricing)
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

        $canViewCosts = (bool) $request->user()->hasPermission('rental_job_cards.view_costs');
        $sort = $request->get('sort', 'sort_order');
        // Cost is only sortable by someone who may see it — an ordering would leak it.
        $allowedSorts = array_merge(['sort_order', 'code', 'description', 'selling_excl'], $canViewCosts ? ['cost_excl'] : []);
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'sort_order';
        }
        $query->orderBy($sort)->orderBy('id');

        $items = $query->paginate(25)->withQueryString();

        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        $catalogueItemTypes = $agency ? RentalCatalogueItemType::active()->where('agency_id', $agency->id)->orderBy('sort_order')->get() : collect();

        return view('corex.rental-catalogue-items.index', compact('items', 'status', 'sort', 'catalogueItemTypes', 'canViewCosts') + ['vat' => $this->vat, 'agency' => $agency]);
    }

    public function create(Request $request): View
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());

        $data = $this->formData($agency);

        return view('corex.rental-catalogue-items.create', $data + [
            'canViewCosts' => (bool) $request->user()->hasPermission('rental_job_cards.view_costs'),
            // A new item starts at the agency's default markup and a blank cost: typing the cost fills in the selling price.
            'pricingInit' => [
                'cost_excl' => null, 'cost_incl' => null, 'selling_excl' => null, 'selling_incl' => null,
                'markup_type' => RentalWorkOrderSetting::catalogueMarkupTypeFor($agency?->id),
                'markup_value' => RentalWorkOrderSetting::catalogueMarkupValueFor($agency?->id),
                'driver' => CatalogueItemPricing::DRIVER_MARKUP,
                'vat_type_id' => $data['defaultVatTypeId'],
                'custom_rate' => null,
            ],
        ]);
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
        $data = $this->formData($agency);
        $item = $rentalCatalogueItem;
        $num = fn ($v) => $v === null ? null : (float) $v;

        return view('corex.rental-catalogue-items.edit', ['item' => $item] + $data + [
            'canViewCosts' => $canViewCosts,
            'pricingInit' => [
                // Cost and markup never reach the page of someone who may not see costs.
                'cost_excl' => $canViewCosts ? $num($item->cost_excl) : null,
                'cost_incl' => $canViewCosts ? $num($item->cost_incl) : null,
                'selling_excl' => $num($item->selling_excl),
                'selling_incl' => $num($item->selling_incl),
                'markup_type' => $item->markup_type ?: RentalWorkOrderSetting::catalogueMarkupTypeFor($agency?->id),
                'markup_value' => $canViewCosts ? ($item->markup_value !== null ? (float) $item->markup_value : ($item->selling_excl === null ? RentalWorkOrderSetting::catalogueMarkupValueFor($agency?->id) : null)) : null,
                // The stored driver — NOT inferred from whether a selling figure exists, or re-saving a markup item would turn it into a fixed-price one.
                'driver' => $item->pricing_driver === RentalCatalogueItem::DRIVER_MARKUP || ($item->pricing_driver === null && $item->selling_excl === null)
                    ? CatalogueItemPricing::DRIVER_MARKUP : CatalogueItemPricing::DRIVER_SELLING_EXCL,
                // An item saved before VAT types were picked shows the agency's default type — what a job card line would use anyway.
                'vat_type_id' => $item->default_rental_vat_type_id ?: $data['defaultVatTypeId'],
                'custom_rate' => $num($item->default_custom_vat_rate),
            ],
        ]);
    }

    public function update(Request $request, RentalCatalogueItem $rentalCatalogueItem): RedirectResponse
    {
        $agency = Agency::withoutGlobalScopes()->find($request->user()->effectiveAgencyId());
        $rentalCatalogueItem->update($this->validated($request, $agency, $rentalCatalogueItem));

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
            'catalogueItemTypes' => $catalogueItemTypes,
            'catalogueUnits' => $catalogueUnits,
            'vatTypes' => $vatTypes,
            'vatTypesForJs' => $vatTypesForJs,
            'agency' => $agency,
            'agencyVatRate' => (float) PerformanceSetting::get('vat_rate', 15, $agency?->id),
            'defaultVatTypeId' => $vatTypes->isNotEmpty() ? RentalVatType::defaultFor($agency->id)?->id : null,
        ];
    }

    /**
     * §17.38 — validates the item and works out the pricing block server-side with the SAME maths as the screen
     * (CatalogueItemPricing). Only the field the user last edited is trusted: `cost_basis` says which cost box was typed,
     * `pricing_driver` says whether markup, selling excl or selling incl was. A user who cannot see costs cannot set (or wipe)
     * a cost or a markup: those stay as stored, and the selling price they type recalculates the markup from the stored cost.
     */
    private function validated(Request $request, ?Agency $agency, ?RentalCatalogueItem $existing = null): array
    {
        $agencyId = $agency?->id;
        $currentId = $request->route('rentalCatalogueItem')?->id;
        $canViewCosts = (bool) $request->user()?->hasPermission('rental_job_cards.view_costs');

        // "12,5" and "1 250.00" are absorbed (BUILD_STANDARD §2) — the box is a text field so the screen can keep 2 decimals as you type.
        foreach (['cost_excl', 'cost_incl', 'selling_excl', 'selling_incl', 'markup_value'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => str_replace(',', '.', preg_replace('/\s+/', '', $request->input($field)))]);
            }
        }

        $money = ['nullable', 'numeric', 'min:0', 'max:99999999.99'];
        // The markup box is an INPUT only when markup is what drives the price. For a selling-price item it shows the figure the server DERIVED from selling and cost,
        // which is negative when the item is sold below cost - and is posted back unchanged on the next Save. It is recalculated, never trusted, so it is not held to
        // "not negative" there (audit 2026-10-20 M1: a below-cost item could not be saved again). A typed markup is still refused when it is negative.
        $markupIsInput = $request->input('pricing_driver') === CatalogueItemPricing::DRIVER_MARKUP;
        $markupRule = ['nullable', 'numeric', $markupIsInput ? 'min:0' : 'min:-99999999', 'max:99999999'];
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
            'cost_excl' => $money,
            'cost_incl' => $money,
            'selling_excl' => $money,
            'selling_incl' => $money,
            'markup_type' => ['nullable', Rule::in([CatalogueItemPricing::MARKUP_RAND, CatalogueItemPricing::MARKUP_PERCENT])],
            'markup_value' => $markupRule,
            'cost_basis' => ['nullable', Rule::in([CatalogueItemPricing::COST_BASIS_EXCL, CatalogueItemPricing::COST_BASIS_INCL])],
            'pricing_driver' => ['nullable', Rule::in([CatalogueItemPricing::DRIVER_MARKUP, CatalogueItemPricing::DRIVER_SELLING_EXCL, CatalogueItemPricing::DRIVER_SELLING_INCL])],
            'default_rental_vat_type_id' => ['nullable', Rule::exists('rental_vat_types', 'id')->where('agency_id', $agencyId)],
            'default_custom_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        // A VAT-registered agency always holds an explicit VAT type on the item (the select has no blank): a missing one is the agency default.
        $vatTypeId = $data['default_rental_vat_type_id'] ?? null;
        if (! $vatTypeId && $agency?->vat_registered) {
            $vatTypeId = RentalVatType::defaultFor($agency->id)?->id;
        }
        $type = $vatTypeId ? RentalVatType::find($vatTypeId) : null;
        $customRate = $type?->rate_mode === RentalVatType::RATE_MODE_CUSTOM_PER_LINE ? ($data['default_custom_vat_rate'] ?? null) : null;
        $rate = $this->pricing->rateFor($agency, $vatTypeId, $customRate);

        $in = [
            'cost_excl' => $data['cost_excl'] ?? null, 'cost_incl' => $data['cost_incl'] ?? null,
            'selling_excl' => $data['selling_excl'] ?? null, 'selling_incl' => $data['selling_incl'] ?? null,
            'markup_type' => $data['markup_type'] ?? null, 'markup_value' => $data['markup_value'] ?? null,
        ];
        $costBasis = $data['cost_basis'] ?? CatalogueItemPricing::COST_BASIS_EXCL;
        $driver = $data['pricing_driver'] ?? CatalogueItemPricing::DRIVER_SELLING_EXCL;

        if (! $canViewCosts) {
            $in['cost_excl'] = $existing?->cost_excl;
            $in['cost_incl'] = null;
            $in['markup_type'] = $existing?->markup_type;
            $in['markup_value'] = $existing?->markup_value;
            $costBasis = CatalogueItemPricing::COST_BASIS_EXCL;
            $driver = $driver === CatalogueItemPricing::DRIVER_SELLING_INCL ? $driver : CatalogueItemPricing::DRIVER_SELLING_EXCL;
            // They cannot see the markup, so saving the item without touching the selling price must not flip a markup-priced item to a fixed price.
            if ($existing?->pricing_driver === RentalCatalogueItem::DRIVER_MARKUP && $existing->markup_value !== null) {
                $same = fn ($posted, $stored) => $posted === null || $posted === '' || ($stored !== null && abs((float) $posted - (float) $stored) < 0.005);
                if ($same($data['selling_excl'] ?? null, $existing->selling_excl) && $same($data['selling_incl'] ?? null, $existing->selling_incl)) {
                    $driver = CatalogueItemPricing::DRIVER_MARKUP;
                }
            }
        }

        $priced = $this->pricing->resolve($in, $rate, $costBasis, $driver, RentalWorkOrderSetting::catalogueMarkupTypeFor($agencyId));

        return [
            'rental_catalogue_item_type_id' => $data['rental_catalogue_item_type_id'],
            'code' => $data['code'],
            'description' => $data['description'],
            'rental_catalogue_unit_id' => $data['rental_catalogue_unit_id'],
            'default_rental_vat_type_id' => $vatTypeId,
            'default_custom_vat_rate' => $customRate,
            'is_active' => $request->boolean('is_active', true),
        ] + $priced;
    }
}
