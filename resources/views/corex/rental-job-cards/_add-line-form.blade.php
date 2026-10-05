{{--
    2026-10-05 rebuild — add a line under a specific task ($taskId set) or
    into the General group ($taskId null). "unit from the agency unit
    list" (Johan) — a free-text line's unit is now a <select> sourced from
    RentalCatalogueUnit, not a typed string; a catalogue item still wins
    with its own unit regardless of what's picked here
    (RentalJobCardService::addLine()).

    Expects: $action, $taskId, $catalogueItems, $catalogueUnits,
    $pricesOn, $vatTypes, $vatRegistered.
--}}
<form method="POST" action="{{ $action }}" class="grid grid-cols-6 gap-2 pt-2 items-end">
    @csrf
    @if($taskId)<input type="hidden" name="rental_job_card_task_id" value="{{ $taskId }}">@endif
    <div>
        <label class="text-xs">Catalogue item</label>
        <select name="rental_catalogue_item_id" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);" onchange="this.form.description.value=''; this.form.type.disabled = !!this.value; this.form.unit.disabled = !!this.value;">
            <option value="">— Free text —</option>
            @foreach($catalogueItems as $ci)
                <option value="{{ $ci->id }}">{{ $ci->name }} ({{ $ci->catalogueItemType->name ?? '—' }}@if($ci->catalogueUnit) &middot; {{ $ci->catalogueUnit->name }}@endif)</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="text-xs">Description</label>
        <input type="text" name="description" maxlength="255" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
    </div>
    <div>
        <label class="text-xs">Type</label>
        <select name="type" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
            <option value="labour">Labour</option>
            <option value="part">Part</option>
        </select>
    </div>
    @if($pricesOn)
        <div>
            <label class="text-xs">Unit</label>
            <select name="unit" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
                <option value="">—</option>
                @foreach($catalogueUnits as $unit)
                    <option value="{{ $unit->name }}">{{ $unit->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="text-xs">Qty</label>
            <input type="number" name="quantity" step="0.01" min="0.01" value="1" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
        </div>
        <div>
            <label class="text-xs">Unit price (R)</label>
            <input type="number" name="unit_price" step="0.01" min="0" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);">
        </div>
        @if($vatRegistered)
        <div>
            <label class="text-xs">VAT type</label>
            <select name="rental_vat_type_id" class="w-full rounded-md px-2 py-1.5 text-xs mt-1" style="border: 1px solid var(--border);"
                    onchange="this.form.custom_vat_rate.classList.toggle('hidden', this.options[this.selectedIndex].dataset.custom !== '1')">
                @foreach($vatTypes as $vt)
                    <option value="{{ $vt->id }}" data-custom="{{ $vt->rate_mode === 'custom_per_line' ? '1' : '0' }}" {{ $vt->is_default ? 'selected' : '' }}>{{ $vt->name }}</option>
                @endforeach
            </select>
            <input type="number" name="custom_vat_rate" step="0.01" min="0" max="100" placeholder="Rate %"
                   class="w-full rounded-md px-2 py-1.5 text-xs mt-1 hidden" style="border: 1px solid var(--border);">
        </div>
        @endif
    @endif
    <div>
        <button type="submit" class="corex-btn-outline text-xs">Add line</button>
    </div>
</form>
