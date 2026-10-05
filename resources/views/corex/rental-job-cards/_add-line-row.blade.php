{{--
    2026-10-05 — Johan: the add-line controls must fit on ONE line at 1366
    and 1536 widths, by fixing column widths only. Shared by BOTH the show
    screen (edit mode, a real form posting to storeLine()) and the create
    screen's draft task builder (Alpine, nothing posted until the whole
    card's Save) — one partial, so the two can never drift apart again.
    No helper text (labels dropped in favour of placeholder/aria-label).

    $mode: 'form' (edit screen — real POST) | 'draft' (create screen —
      Alpine-only, nothing submitted here; the pushed line object is what
      eventually gets synced into hidden tasks[][lines][]/general_lines[]
      inputs elsewhere on the page).
    Form mode expects: $action, $taskId (nullable).
    Draft mode expects: $refPrefix (e.g. 'free'/'gen' — x-ref namespacing so
      a task's own refs never collide with another task's or General's),
      $addLineCall (the exact Alpine method call string for the "+" button,
      e.g. "addFreeTextLine(task, $refs, '')" or "addFreeTextLine(null, $refs, 'gen')").
    Common: $catalogueItems, $catalogueUnits, $pricesOn, $vatTypes, $vatRegistered.

    Column widths (fixed, not equal-share — this is what stops the wrap):
    catalogue item 150px | description 1fr (min 170px) | type 72px |
    unit 68px | qty 56px | unit price 92px | VAT type 92px | + button 34px.
--}}
@php
    $cols = ['150px', 'minmax(170px,1fr)', '72px'];
    if ($pricesOn) {
        $cols[] = '68px';
        $cols[] = '56px';
        $cols[] = '92px';
        if ($vatRegistered) {
            $cols[] = '92px';
        }
    }
    $cols[] = '34px';
    $gridStyle = 'display:grid; grid-template-columns: ' . implode(' ', $cols) . '; gap: 6px; align-items:center;';
@endphp
@if($mode === 'form')
<form method="POST" action="{{ $action }}" class="pt-2" style="{{ $gridStyle }}">
    @csrf
    @if($taskId)<input type="hidden" name="rental_job_card_task_id" value="{{ $taskId }}">@endif
@else
<div class="pt-2" style="{{ $gridStyle }}">
@endif
    <select {{ $mode === 'form' ? 'name=rental_catalogue_item_id' : 'x-ref=' . $refPrefix . 'CatalogueItem' }}
            aria-label="Catalogue item" title="Catalogue item"
            class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"
            @if($mode === 'form')
                onchange="this.form.description.value=''; this.form.type.disabled = !!this.value; this.form.unit.disabled = !!this.value;"
            @else
                @change="addDraftLine({{ $taskExpr ?? 'null' }}, $event.target, '{{ $refPrefix }}')"
            @endif
    >
        <option value="">— Free text —</option>
        @foreach($catalogueItems as $ci)
            <option value="{{ $ci->id }}">{{ $ci->name }} ({{ $ci->catalogueItemType->name ?? '—' }}@if($ci->catalogueUnit) &middot; {{ $ci->catalogueUnit->name }}@endif)</option>
        @endforeach
    </select>
    <input type="text" {{ $mode === 'form' ? 'name=description' : 'x-ref=' . $refPrefix . 'Desc' }}
           maxlength="255" placeholder="Description" aria-label="Description"
           class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
    <select {{ $mode === 'form' ? 'name=type' : 'x-ref=' . $refPrefix . 'Type' }}
            aria-label="Type" title="Type"
            class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
        <option value="labour">Labour</option>
        <option value="part">Part</option>
    </select>
    @if($pricesOn)
        <select {{ $mode === 'form' ? 'name=unit' : 'x-ref=' . $refPrefix . 'Unit' }}
                aria-label="Unit" title="Unit"
                class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
            <option value="">—</option>
            @foreach($catalogueUnits as $unit)
                <option value="{{ $unit->name }}">{{ $unit->name }}</option>
            @endforeach
        </select>
        <input type="number" {{ $mode === 'form' ? 'name=quantity' : 'x-ref=' . $refPrefix . 'Qty' }}
               step="0.01" min="0.01" value="1" aria-label="Qty" title="Qty"
               class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
        <input type="number" {{ $mode === 'form' ? 'name=unit_price' : 'x-ref=' . $refPrefix . 'UnitPrice' }}
               step="0.01" min="0" aria-label="Unit price" title="Unit price (R)"
               class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
        @if($vatRegistered)
        <select {{ $mode === 'form' ? 'name=rental_vat_type_id' : 'x-ref=' . $refPrefix . 'VatType' }}
                aria-label="VAT type" title="VAT type"
                class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);"
                @if($mode === 'form')
                    onchange="this.form.custom_vat_rate.classList.toggle('hidden', this.options[this.selectedIndex].dataset.custom !== '1')"
                @endif
        >
            @foreach($vatTypes as $vt)
                <option value="{{ $vt->id }}" data-custom="{{ $vt->rate_mode === 'custom_per_line' ? '1' : '0' }}" {{ $vt->is_default ? 'selected' : '' }}>{{ $vt->shortLabel() }}</option>
            @endforeach
        </select>
        @if($mode === 'form')
        <input type="number" name="custom_vat_rate" step="0.01" min="0" max="100" placeholder="Rate %"
               class="w-full rounded-md px-2 py-1.5 text-xs mt-1 hidden" style="border: 1px solid var(--border); grid-column: 1 / -1;">
        @endif
        @endif
    @endif
    <button type="{{ $mode === 'form' ? 'submit' : 'button' }}"
            @if($mode === 'draft') @click="{{ $addLineCall }}" @endif
            aria-label="Add line" title="Add line"
            class="corex-btn-outline text-xs" style="padding: 6px 0; text-align: center;">+</button>
@if($mode === 'form')
</form>
@else
</div>
@endif
