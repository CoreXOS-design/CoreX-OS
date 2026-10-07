@extends('layouts.corex')

{{--
    AT-442 — agency parts & labour catalogue. Pastel-style enhancement,
    2026-10-05: type/unit now pick from the agency's own configurable
    lists; default price is VAT-type-aware and stored excl-VAT always
    (RentalCatalogueItemController::validated()).
--}}

@section('content')
<div class="p-6 max-w-2xl space-y-4">
    <div class="flex items-center justify-between">
        <h1 class="text-lg font-semibold">Edit Catalogue Item</h1>
        <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="text-xs underline" style="color:var(--text-muted);">Manage types &amp; units</a>
    </div>

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">{{ $errors->first() }}</div>
    @endif

    <form method="POST" action="{{ route('corex.rental-catalogue-items.update', $item) }}" class="space-y-4"
          x-data="{
              vatRegistered: {{ $agency?->vat_registered ? 'true' : 'false' }},
              isIncl: {{ ($agency?->vat_capture_mode === \App\Models\Agency::VAT_CAPTURE_INCL) ? 'true' : 'false' }},
              agencyRate: {{ (float) $agencyVatRate }},
              vatTypes: {{ \Illuminate\Support\Js::from($vatTypesForJs) }},
              vatTypeId: '{{ old('default_rental_vat_type_id', $item->default_rental_vat_type_id ?? '') }}',
              customRate: {{ (float) old('default_custom_vat_rate', $item->default_custom_vat_rate ?? 0) }},
              price: {{ (float) old('default_price', $itemPrice ?? 0) }},
              selectedType() { return this.vatTypes.find(t => String(t.id) === String(this.vatTypeId)) || null; },
              rate() {
                  if (!this.vatRegistered) return 0;
                  const t = this.selectedType();
                  if (!t) return 0;
                  if (t.rate_mode === 'agency_rate') return this.agencyRate;
                  if (t.rate_mode === 'fixed') return t.fixed_rate;
                  return this.customRate || 0;
              },
              mode() { return !this.vatRegistered ? 'none' : (this.rate() > 0 ? 'split' : 'zero'); },
              isCustom() { const t = this.selectedType(); return !!t && t.rate_mode === 'custom_per_line'; },
              excl() {
                  if (this.rate() <= 0) return this.price || 0;
                  return this.isIncl ? Math.round((this.price / (1 + this.rate() / 100)) * 100) / 100 : (this.price || 0);
              },
              incl() {
                  if (this.rate() <= 0) return this.price || 0;
                  return this.isIncl ? (this.price || 0) : Math.round((this.price * (1 + this.rate() / 100)) * 100) / 100;
              },
          }">
        @csrf
        @method('PUT')
        <div>
            <label class="prop-label">Type</label>
            <select name="rental_catalogue_item_type_id" required class="prop-select w-full">
                <option value="">— Select —</option>
                @foreach($catalogueItemTypes as $t)
                    <option value="{{ $t->id }}" @selected((string) old('rental_catalogue_item_type_id', $item->rental_catalogue_item_type_id) === (string) $t->id)>{{ $t->name }}</option>
                @endforeach
            </select>
            @if($catalogueItemTypes->isEmpty())
                <p class="text-[11px] mt-1" style="color:var(--ds-crimson);">No catalogue types configured yet — <a href="{{ route('admin.company-settings') }}#catalogue-item-types" class="underline">add one in Company Settings</a>.</p>
            @endif
        </div>
        <div class="grid grid-cols-3 gap-3">
            <div>
                <label class="prop-label">Code</label>
                <input type="text" name="code" value="{{ old('code', $item->code) }}" required maxlength="50" style="font-family: monospace;" class="prop-input w-full">
            </div>
            <div class="col-span-2">
                <label class="prop-label">Description</label>
                <input type="text" name="description" value="{{ old('description', $item->description) }}" required maxlength="500" class="prop-input w-full">
            </div>
        </div>
        <div>
            <label class="prop-label">Unit</label>
            <select name="rental_catalogue_unit_id" required class="prop-select w-full">
                <option value="">— Select —</option>
                @foreach($catalogueUnits as $u)
                    <option value="{{ $u->id }}" @selected((string) old('rental_catalogue_unit_id', $item->rental_catalogue_unit_id) === (string) $u->id)>{{ $u->name }}</option>
                @endforeach
            </select>
            @if($catalogueUnits->isEmpty())
                <p class="text-[11px] mt-1" style="color:var(--ds-crimson);">No units configured yet — <a href="{{ route('admin.company-settings') }}#catalogue-units" class="underline">add one in Company Settings</a>.</p>
            @endif
        </div>

        @if($vatTypes->isNotEmpty())
        <div>
            <label class="prop-label">Default VAT type</label>
            <select name="default_rental_vat_type_id" x-model="vatTypeId" class="prop-select w-full">
                <option value="">— Agency default —</option>
                @foreach($vatTypes as $vt)
                    <option value="{{ $vt->id }}">{{ $vt->name }}</option>
                @endforeach
            </select>
            <p class="text-[11px] mt-1" style="color:var(--text-muted);">A job card line that picks this item inherits this VAT type — still editable per line.</p>
        </div>
        <div x-show="isCustom()">
            <label class="prop-label">Custom VAT rate %</label>
            <input type="number" name="default_custom_vat_rate" x-model.number="customRate" step="0.01" min="0" max="100" class="prop-input w-full">
        </div>
        @endif

        <template x-if="mode() === 'none'">
            <div>
                <label class="prop-label">Price (R, optional)</label>
                <input type="number" name="default_price" x-model.number="price" step="0.01" min="0" class="prop-input w-full">
            </div>
        </template>
        <template x-if="mode() === 'zero'">
            <div>
                <label class="prop-label">Price (no VAT) (R, optional)</label>
                <input type="number" name="default_price" x-model.number="price" step="0.01" min="0" class="prop-input w-full">
            </div>
        </template>
        <template x-if="mode() === 'split'">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="prop-label">Excl VAT (R)</label>
                    @if($agency?->vat_capture_mode !== \App\Models\Agency::VAT_CAPTURE_INCL)
                        <input type="number" name="default_price" x-model.number="price" step="0.01" min="0" class="prop-input w-full">
                    @else
                        <input type="text" :value="excl().toFixed(2)" readonly class="prop-input w-full" style="opacity:0.7;">
                    @endif
                </div>
                <div>
                    <label class="prop-label">Incl VAT (R)</label>
                    @if($agency?->vat_capture_mode === \App\Models\Agency::VAT_CAPTURE_INCL)
                        <input type="number" name="default_price" x-model.number="price" step="0.01" min="0" class="prop-input w-full">
                    @else
                        <input type="text" :value="incl().toFixed(2)" readonly class="prop-input w-full" style="opacity:0.7;">
                    @endif
                </div>
            </div>
        </template>

        @if($canViewCosts ?? false)
        <div data-catalogue-cost>
            <label class="prop-label">{{ $costLabel }}</label>
            <input type="number" name="default_cost" value="{{ old('default_cost', ($itemCost !== null ? number_format((float) $itemCost, 2, '.', '') : null)) }}" step="0.01" min="0" class="prop-input w-full">
            <p class="text-[11px] mt-1" style="color:var(--text-muted);">What this usually costs the agency. It prefills the cost when the item is added to a job card — the crew never sees it, and nothing is charged from it unless you set a markup.</p>
        </div>
        @endif

        <div class="flex items-center gap-2">
            <input type="checkbox" id="is_active" name="is_active" value="1" @checked(old('is_active', $item->is_active)) class="rounded">
            <label for="is_active" class="prop-label !mb-0">Active</label>
        </div>
        <div class="flex justify-end gap-2 pt-2">
            <a href="{{ route('corex.rental-catalogue-items.index') }}" class="corex-btn-outline text-xs">Cancel</a>
            <button type="submit" class="corex-btn-primary text-xs">Save changes</button>
        </div>
    </form>
</div>
@endsection
