{{--
    2026-10-05 — Johan: the add-line controls must fit on ONE line at 1366
    and 1536 widths, by fixing column widths only. Shared by BOTH the show
    screen (edit mode, a real form posting to storeLine()) and the create
    screen's draft task builder (Alpine, nothing posted until the whole
    card's Save) — one partial, so the two can never drift apart again.
    No helper text (labels dropped in favour of placeholder/aria-label);
    _line-columns-header.blade.php provides the column names instead.

    2026-10-05 round 3 (Johan QA1 findings A/B/C) — rebuilt the catalogue
    picker and the field pre-fill:
    - A: the picker now searches by CODE or DESCRIPTION and shows
      "CODE — Description" (client-side filter over the agency's own
      active items — small lists, no new search endpoint needed).
    - B: picking an item now actually FILLS description/type/unit/unit
      price/VAT — none of it was being set before (only description was
      cleared and type/unit were DISABLED, which is also why Type always
      showed "Labour": nothing ever set the <select>'s value, so the
      browser default (first option) won regardless of the item's own
      type). Every field stays fully editable afterward (nothing disabled
      anywhere in this partial any more) — Johan's own instruction this
      round.
    - Type's own <select> now lists the agency's actual configured types
      (not a hardcoded Labour/Part) — each option's value is still that
      type's underlying KIND (labour|part), which is all
      RentalJobCardService::addLine()/RentalReportService ever read from
      a line; an agency naming its own types (e.g. "Subcontractor") just
      shows its own name here, exactly like the catalogue item form's own
      Type select already does.

    $mode: 'form' (edit screen — real POST) | 'draft' (create screen —
      Alpine-only, nothing submitted here; the pushed line object is what
      eventually gets synced into hidden tasks[][lines][]/general_lines[]
      inputs elsewhere on the page).
    Form mode expects: $action, $taskId (nullable).
    Draft mode expects: $refPrefix (e.g. 'free'/'gen' — x-ref namespacing so
      a task's own refs never collide with another task's or General's),
      $addLineCall (the exact Alpine method call string for the "+" button,
      e.g. "addLineFromRow(task, $refs, '')" or "addLineFromRow(null, $refs, 'gen')").
    Common: $catalogueItemsForJs (array, id/code/description/label/kind/
      unit/priceForLine/vatTypeId/customVatRate), $catalogueItemTypes,
      $catalogueUnits, $pricesOn, $vatTypes, $vatRegistered.

    Column widths: App\Support\RentalJobCardLineGrid — the ONE source of
    truth, shared with the header row and the lines table, so this file
    can never silently drift out of alignment with either again.
--}}
@php
    $gridStyle = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vatRegistered);
    $fieldStyle = \App\Support\RentalJobCardLineGrid::fieldStyle();
    $rowId = $refPrefix ?? ('t' . ($taskId ?? 'gen'));
@endphp
@if($mode === 'form')
<form method="POST" action="{{ $action }}" class="pt-2"
      x-data="catalogueLinePicker({{ \Illuminate\Support\Js::from($catalogueItemsForJs) }})">
    @csrf
    @if($taskId)<input type="hidden" name="rental_job_card_task_id" value="{{ $taskId }}">@endif
@else
<div class="pt-2" x-data="catalogueLinePicker({{ \Illuminate\Support\Js::from($catalogueItemsForJs) }})">
@endif
    <div style="{{ $gridStyle }}" class="relative">
        <div class="relative" style="min-width:0;">
            <input type="text" x-model="query" @focus="open = true" @click="open = true"
                   @keydown.escape="open = false" @keydown.down.prevent="moveSelection(1)" @keydown.up.prevent="moveSelection(-1)"
                   @keydown.enter.prevent="pickHighlighted()"
                   aria-label="Item" title="Item — search by code or description" placeholder="Search item…" autocomplete="off"
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            <input type="hidden" {{ $mode === 'form' ? 'name=rental_catalogue_item_id' : 'x-ref=' . $refPrefix . 'CatalogueItem' }} x-model="selectedId">
            <div x-show="open" @click.outside="open = false" x-cloak
                 class="absolute z-20 mt-1 w-full max-h-56 overflow-y-auto rounded-md text-xs"
                 style="background: var(--surface); border: 1px solid var(--border); box-shadow: 0 4px 12px rgba(0,0,0,0.12);">
                <template x-for="(it, idx) in filtered()" :key="it.id">
                    <div @click="pick(it)"
                         :class="idx === highlighted ? 'font-semibold' : ''"
                         style="padding: 6px 8px; cursor: pointer;"
                         :style="idx === highlighted ? 'background: var(--surface-2);' : ''"
                         x-text="it.label"></div>
                </template>
                <div x-show="filtered().length === 0" style="padding: 6px 8px; color: var(--text-muted);">No match — free text below</div>
                <div @click="clear()"
                     style="padding: 6px 8px; cursor: pointer; border-top: 1px solid var(--border); color: var(--text-muted);">— Free text —</div>
            </div>
        </div>
        <input type="text" {{ $mode === 'form' ? 'name=description' : 'x-ref=' . $refPrefix . 'Desc' }}
               maxlength="255" placeholder="Description" aria-label="Description"
               class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
        <select {{ $mode === 'form' ? 'name=type' : 'x-ref=' . $refPrefix . 'Type' }}
                aria-label="Type" title="Type"
                class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            @forelse($catalogueItemTypes as $catType)
                <option value="{{ $catType->kind }}">{{ $catType->name }}</option>
            @empty
                <option value="labour">Labour</option>
                <option value="part">Part</option>
            @endforelse
        </select>
        @if($pricesOn)
            <select {{ $mode === 'form' ? 'name=unit' : 'x-ref=' . $refPrefix . 'Unit' }}
                    aria-label="Unit" title="Unit"
                    class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
                <option value="">—</option>
                @foreach($catalogueUnits as $unit)
                    <option value="{{ $unit->name }}">{{ $unit->name }}</option>
                @endforeach
            </select>
            <input type="number" {{ $mode === 'form' ? 'name=quantity' : 'x-ref=' . $refPrefix . 'Qty' }}
                   step="0.01" min="0.01" value="1" aria-label="Qty" title="Qty"
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            <input type="number" {{ $mode === 'form' ? 'name=unit_price' : 'x-ref=' . $refPrefix . 'UnitPrice' }}
                   step="0.01" min="0" aria-label="Unit price" title="Unit price (R)"
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            @if($vatRegistered)
            <select {{ $mode === 'form' ? 'name=rental_vat_type_id' : 'x-ref=' . $refPrefix . 'VatType' }}
                    aria-label="VAT type" title="VAT type"
                    class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}"
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
                class="corex-btn-outline text-xs" style="padding: 6px 0; text-align: center; min-width:0;">+</button>
    </div>
@if($mode === 'form')
</form>
@else
</div>
@endif
