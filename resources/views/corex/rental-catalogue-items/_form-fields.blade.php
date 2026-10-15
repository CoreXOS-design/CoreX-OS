{{--
    .ai/specs/rental-work-orders.md §17.38 — the catalogue item card, shared by create and edit: an ITEM block (type, code, unit,
    active, description) and a PRICING block (Cost / Markup / Selling, each Excl VAT | VAT | Incl VAT).

    The maths in catalogueItemPricing() below is the screen's live preview of CatalogueItemPricing (PHP), which re-does it on save —
    only the field last edited (cost_basis / pricing_driver) is trusted by the server. Needs: $item (null on create), $pricingInit,
    $canViewCosts, $agency, $vatTypes, $vatTypesForJs, $agencyVatRate, $catalogueItemTypes, $catalogueUnits.
--}}
@php
    $init = $pricingInit;
    // After a failed save the form comes back with what was typed, not what was stored.
    foreach (['cost_excl', 'cost_incl', 'selling_excl', 'selling_incl', 'markup_value'] as $k) {
        if (old($k) !== null) { $init[$k] = old($k); }
    }
    if (old('markup_type')) { $init['markup_type'] = old('markup_type'); }
    if (old('pricing_driver')) { $init['driver'] = old('pricing_driver'); }
    if (old('cost_basis')) { $init['cost_basis'] = old('cost_basis'); }
    if (old('default_rental_vat_type_id')) { $init['vat_type_id'] = old('default_rental_vat_type_id'); }
    if (old('default_custom_vat_rate') !== null) { $init['custom_rate'] = old('default_custom_vat_rate'); }
    $cfg = [
        'vatRegistered' => (bool) $agency?->vat_registered,
        'agencyRate' => (float) $agencyVatRate,
        'vatTypes' => $vatTypesForJs,
        'canCosts' => (bool) $canViewCosts,
    ];
    $itemOrNull = $item ?? null;
@endphp

<style>
    .cip-grid { display: grid; grid-template-columns: 6rem repeat(3, minmax(0, 1fr)); gap: .5rem .75rem; align-items: center; }
    .cip-grid .cip-head { font-size: .75rem; font-weight: 600; color: var(--text-muted); text-align: right; }
    .cip-grid .cip-label { font-size: .75rem; font-weight: 600; color: var(--text-muted); }
    .cip-grid input[type=text] { width: 100%; text-align: right; font-variant-numeric: tabular-nums; }
    .cip-grid input[readonly] { opacity: .7; }
    .cip-radios { display: flex; justify-content: center; gap: .75rem; font-size: .8rem; }
    .cip-radios label { display: inline-flex; align-items: center; gap: .25rem; cursor: pointer; }
</style>

<script>
    window.catalogueItemPricing = function (init, cfg) {
        // Amounts to 2 decimals, half away from zero — the same rounding the server applies.
        const r2 = (x) => (x < 0 ? -1 : 1) * Math.round(Math.abs(x) * 100 + 1e-7) / 100;
        const num = (v) => {
            if (v === null || v === undefined) return null;
            const t = String(v).trim().replace(/\s/g, '').replace(',', '.');
            return t === '' || isNaN(Number(t)) ? null : Number(t);
        };
        const f2 = (n) => (n === null ? '' : r2(n).toFixed(2));
        return {
            vatTypes: cfg.vatTypes, vatRegistered: cfg.vatRegistered, agencyRate: cfg.agencyRate, canCosts: cfg.canCosts,
            vatTypeId: String(init.vat_type_id ?? ''),
            customRate: init.custom_rate === null || init.custom_rate === undefined ? '' : String(init.custom_rate),
            costExcl: f2(num(init.cost_excl)), costIncl: f2(num(init.cost_incl)),
            sellExcl: f2(num(init.selling_excl)), sellIncl: f2(num(init.selling_incl)),
            markupType: init.markup_type === 'rand' ? 'rand' : 'percent',
            markupValue: f2(num(init.markup_value)),
            driver: init.driver || 'selling_excl',
            costBasis: init.cost_basis || 'excl',

            selectedType() { return this.vatTypes.find(t => String(t.id) === String(this.vatTypeId)) || null; },
            isCustom() { const t = this.selectedType(); return !!t && t.rate_mode === 'custom_per_line'; },
            rate() {
                if (!this.vatRegistered) return 0;
                const t = this.selectedType();
                if (!t) return 0;
                if (t.rate_mode === 'agency_rate') return this.agencyRate;
                if (t.rate_mode === 'fixed') return t.fixed_rate;
                return Math.max(0, num(this.customRate) || 0);
            },
            incl(x) { return r2(x * (1 + this.rate() / 100)); },
            excl(x) { return this.rate() > 0 ? r2(x / (1 + this.rate() / 100)) : r2(x); },
            sellingFrom(cost, mv, type) { return r2(type === 'rand' ? cost + mv : cost * (1 + mv / 100)); },
            markupFrom(cost, sell, type) { return type === 'rand' ? r2(sell - cost) : (cost > 0 ? Math.round((sell - cost) / cost * 1e6) / 1e4 : null); },

            // Keeps the pair markup <-> selling in step: whichever the user typed last stays; the other follows.
            apply() {
                const cost = num(this.costExcl), mv = num(this.markupValue), sell = num(this.sellExcl);
                if (this.driver === 'markup') {
                    if (cost !== null && mv !== null) {
                        const s = this.sellingFrom(cost, mv, this.markupType);
                        this.sellExcl = f2(s); this.sellIncl = f2(this.incl(s));
                    }
                } else if (sell !== null) {
                    const m = cost !== null ? this.markupFrom(cost, sell, this.markupType) : null;
                    this.markupValue = f2(m);
                }
            },
            typeCostExcl(v) { this.costBasis = 'excl'; this.costExcl = v; const c = num(v); this.costIncl = c === null ? '' : f2(this.incl(c)); this.apply(); },
            typeCostIncl(v) { this.costBasis = 'incl'; this.costIncl = v; const c = num(v); this.costExcl = c === null ? '' : f2(this.excl(c)); this.apply(); },
            typeMarkup(v) { this.driver = 'markup'; this.markupValue = v; this.apply(); },
            typeSellExcl(v) { this.driver = 'selling_excl'; this.sellExcl = v; const s = num(v); this.sellIncl = s === null ? '' : f2(this.incl(s)); this.apply(); },
            typeSellIncl(v) { this.driver = 'selling_incl'; this.sellIncl = v; const s = num(v); this.sellExcl = s === null ? '' : f2(this.excl(s)); this.apply(); },
            // R <-> % converts the figure so the selling price does not jump.
            setMarkupType(t) {
                if (t === this.markupType) return;
                const cost = num(this.costExcl), mv = num(this.markupValue);
                if (cost !== null && mv !== null) {
                    const m = this.markupFrom(cost, this.sellingFrom(cost, mv, this.markupType), t);
                    this.markupValue = f2(m);
                }
                this.markupType = t;
            },
            // A new VAT type recalculates every incl figure from the excl ones.
            vatChanged() {
                this.costBasis = 'excl';
                if (this.driver === 'selling_incl') this.driver = 'selling_excl';
                const c = num(this.costExcl), s = num(this.sellExcl);
                this.costIncl = c === null ? '' : f2(this.incl(c));
                this.sellIncl = s === null ? '' : f2(this.incl(s));
                this.apply();
            },
            tidy(prop) { this[prop] = f2(num(this[prop])); },
            vat(exclProp, inclProp) {
                const e = num(this[exclProp]), i = num(this[inclProp]);
                return e === null || i === null ? '' : f2(i - e);
            },
            markupRand() {
                const cost = num(this.costExcl), mv = num(this.markupValue), sell = num(this.sellExcl);
                if (cost === null) return '';
                if (this.driver !== 'markup' && sell !== null) return f2(sell - cost);
                if (mv === null) return '';
                return f2(this.markupType === 'rand' ? mv : cost * mv / 100);
            },
        };
    };
</script>

<div x-data="catalogueItemPricing({{ \Illuminate\Support\Js::from($init) }}, {{ \Illuminate\Support\Js::from($cfg) }})" class="space-y-4">
    {{-- ITEM --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-3 items-end">
            <div>
                <label class="prop-label">Type</label>
                <select name="rental_catalogue_item_type_id" required class="prop-select w-full">
                    <option value="">— Select —</option>
                    @foreach($catalogueItemTypes as $t)
                        <option value="{{ $t->id }}" @selected((string) old('rental_catalogue_item_type_id', $itemOrNull?->rental_catalogue_item_type_id) === (string) $t->id)>{{ $t->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="prop-label">Code</label>
                <input type="text" name="code" value="{{ old('code', $itemOrNull?->code) }}" required maxlength="50" style="font-family: monospace;" class="prop-input w-full">
            </div>
            <div>
                <label class="prop-label">Unit</label>
                <select name="rental_catalogue_unit_id" required class="prop-select w-full">
                    <option value="">— Select —</option>
                    @foreach($catalogueUnits as $u)
                        <option value="{{ $u->id }}" @selected((string) old('rental_catalogue_unit_id', $itemOrNull?->rental_catalogue_unit_id) === (string) $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="flex items-center gap-2 pb-2">
                <input type="hidden" name="is_active" value="0">
                <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $itemOrNull?->is_active ?? true)) class="rounded">
                <label for="is_active" class="prop-label !mb-0">Active</label>
            </div>
        </div>
        @if($catalogueItemTypes->isEmpty())
            <p class="text-[11px]" style="color:var(--ds-crimson);">No catalogue types configured yet — <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="underline">add one in Company Settings</a>.</p>
        @endif
        @if($catalogueUnits->isEmpty())
            <p class="text-[11px]" style="color:var(--ds-crimson);">No units configured yet — <a href="{{ route('admin.company-settings') }}#catalogue-units" class="underline">add one in Company Settings</a>.</p>
        @endif
        <div>
            <label class="prop-label">Description</label>
            <input type="text" name="description" value="{{ old('description', $itemOrNull?->description) }}" required maxlength="500" class="prop-input w-full">
        </div>
    </div>

    {{-- PRICING --}}
    <div class="rounded-md p-4 space-y-3" style="background: var(--surface); border: 1px solid var(--border);" data-catalogue-pricing>
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-sm font-semibold">Pricing</h2>
            @if($vatTypes->isNotEmpty())
                <div class="flex items-center gap-2">
                    <label for="default_rental_vat_type_id" class="prop-label !mb-0">VAT type</label>
                    <select id="default_rental_vat_type_id" name="default_rental_vat_type_id" x-model="vatTypeId" @change="vatChanged()" class="prop-select">
                        @foreach($vatTypes as $vt)
                            <option value="{{ $vt->id }}">{{ $vt->name }}</option>
                        @endforeach
                    </select>
                    <template x-if="isCustom()">
                        <span class="inline-flex items-center gap-1">
                            <input type="number" name="default_custom_vat_rate" x-model="customRate" @input="vatChanged()" step="0.01" min="0" max="100" aria-label="Custom VAT rate %" class="prop-input" style="width:5rem; text-align:right;">
                            <span class="text-xs" style="color:var(--text-muted);">%</span>
                        </span>
                    </template>
                </div>
            @endif
        </div>

        <input type="hidden" name="pricing_driver" :value="driver">
        <input type="hidden" name="cost_basis" :value="costBasis">

        <div class="cip-grid">
            <span></span>
            <span class="cip-head">Excl VAT</span>
            <span class="cip-head">VAT</span>
            <span class="cip-head">Incl VAT</span>

            @if($canViewCosts)
                <span class="cip-label">Cost</span>
                <input type="text" inputmode="decimal" name="cost_excl" :value="costExcl" @input="typeCostExcl($event.target.value)" @change="tidy('costExcl')" class="prop-input" autocomplete="off">
                <input type="text" :value="vat('costExcl', 'costIncl')" readonly tabindex="-1" class="prop-input" aria-label="Cost VAT">
                <input type="text" inputmode="decimal" name="cost_incl" :value="costIncl" @input="typeCostIncl($event.target.value)" @change="tidy('costIncl')" class="prop-input" autocomplete="off">

                <span class="cip-label">Markup</span>
                <input type="text" inputmode="decimal" name="markup_value" :value="markupValue" @input="typeMarkup($event.target.value)" @change="tidy('markupValue')" class="prop-input" autocomplete="off" aria-label="Markup value">
                <div class="cip-radios">
                    <input type="hidden" name="markup_type" :value="markupType">
                    <label><input type="radio" :checked="markupType === 'rand'" @change="setMarkupType('rand')"> R</label>
                    <label><input type="radio" :checked="markupType === 'percent'" @change="setMarkupType('percent')"> %</label>
                </div>
                <input type="text" :value="markupRand() === '' ? '' : '= R ' + markupRand()" readonly tabindex="-1" class="prop-input" aria-label="Markup in rand">
            @endif

            <span class="cip-label">Selling</span>
            <input type="text" inputmode="decimal" name="selling_excl" :value="sellExcl" @input="typeSellExcl($event.target.value)" @change="tidy('sellExcl')" class="prop-input" autocomplete="off">
            <input type="text" :value="vat('sellExcl', 'sellIncl')" readonly tabindex="-1" class="prop-input" aria-label="Selling VAT">
            <input type="text" inputmode="decimal" name="selling_incl" :value="sellIncl" @input="typeSellIncl($event.target.value)" @change="tidy('sellIncl')" class="prop-input" autocomplete="off">
        </div>
    </div>
</div>
