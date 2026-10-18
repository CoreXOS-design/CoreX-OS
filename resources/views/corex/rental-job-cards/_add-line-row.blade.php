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

    2026-10-05 round 4 (Johan, "saved lines cannot be changed") — a third
    mode, 'edit': the SAME grid, SAME fields, prefilled from an existing
    line and posting PUT to updateLine(). Deliberately one partial rather
    than a second near-copy, so an edited line's controls can never drift
    from the add row's (the same reason this file exists at all). The
    catalogue list is read from window.jcCatalogueItems (printed ONCE per
    page by show.blade.php) instead of being embedded per row — a card
    with 30 lines would otherwise ship 30 copies of the catalogue.

    Item dropdown (round 4, item 3): the list is teleported to <body> and
    positioned `fixed` from the Item input's own rectangle — rendered in
    place it was as narrow as the Item box (100px) and clipped by the
    scrolling left panel (overflow-y:auto on an ancestor clips
    absolutely-positioned descendants on BOTH axes). One line per item, at
    least as wide as Item + Description, flips above the input when there
    is no room below, arrow keys + Enter + Escape.

    $mode: 'form' (edit screen — real POST) | 'draft' (create screen —
      Alpine-only, nothing submitted here; the pushed line object is what
      eventually gets synced into hidden tasks[][lines][]/general_lines[]
      inputs elsewhere on the page) | 'edit' (a saved line, PUT).
    Form mode expects: $action, $taskId (nullable).
    Edit mode expects: $line (RentalJobCardLine), $jobCard.
    Draft mode expects: $refPrefix (e.g. 'free'/'gen' — x-ref namespacing so
      a task's own refs never collide with another task's or General's),
      $addLineCall (the exact Alpine method call string for the "+" button,
      e.g. "addLineFromRow(task, $refs, '')" or "addLineFromRow(null, $refs, 'gen')").
    Common: $catalogueItemsForJs (array, id/code/description/label/kind/
      unit/priceForLine/vatTypeId/customVatRate — not used in edit mode),
      $catalogueItemTypes, $catalogueUnits, $pricesOn, $vatTypes, $vatRegistered.

    §17.4 (maintenance flow, Build 1) — optional $showCost (viewer holds rental_job_cards.view_costs) and
    $canPrice (viewer holds rental_job_cards.price). The Unit price column is now SELLING: left blank it is
    priced by the §17.4.3 rules from the cost (it shows "auto"); typing a price makes it the office's own word.
    A COST box exists only for someone who can both see and set costs (nobody edits a figure they cannot see),
    a Margin placeholder keeps the grid aligned, and the edit row gains a markup control (% or R on this
    line) and "Back to automatic". Without $canPrice the selling/cost boxes are not in the markup at all and
    the server ignores any such field a hand-made POST adds (RentalJobCardController).

    Column widths: App\Support\RentalJobCardLineGrid — the ONE source of
    truth, shared with the header row and the lines table, so this file
    can never silently drift out of alignment with either again.
--}}
@php
    $showCost = ($showCost ?? false) && $pricesOn;
    $canPrice = ($canPrice ?? false) && $pricesOn;
    $canEditCost = $showCost && $canPrice;
    $gridStyle = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vatRegistered, $showCost);
    $fieldStyle = \App\Support\RentalJobCardLineGrid::fieldStyle();
    $rowId = $refPrefix ?? ('t' . ($taskId ?? 'gen'));
    $isEdit = $mode === 'edit';
    $named = $mode !== 'draft'; // form + edit post real named fields; draft uses x-ref.

    if ($isEdit) {
        // A failed validation redirects back with the posted input — re-open
        // THIS line's editor with what the agent typed, not the saved values.
        $oldTarget = (string) old('_edit_line_id') === (string) $line->id;
        $val = fn (string $key, $default) => $oldTarget ? old($key, $default) : $default;
        $selectedItemId = $val('rental_catalogue_item_id', $line->rental_catalogue_item_id);
        $selectedType = $val('type', $line->type);
        $selectedUnit = $val('unit', $line->unit);
        $selectedVatId = $val('rental_vat_type_id', $line->rental_vat_type_id);
        $customRate = $val('custom_vat_rate', $line->custom_vat_rate);
        $priceValue = $val('unit_price', $line->unit_price !== null ? number_format((float) $line->unit_price, 2, '.', '') : '');
        $costValue = $val('unit_cost', $line->unit_cost !== null ? number_format((float) $line->unit_cost, 2, '.', '') : '');
        $markupTypeValue = $val('markup_type', $line->markup_type ?? '');
        $markupAmountValue = $val('markup_value', $line->markup_value !== null ? number_format((float) $line->markup_value, 2, '.', '') : '');
        $qtyValue = $val('quantity', rtrim(rtrim(number_format((float) $line->quantity, 2, '.', ''), '0'), '.'));
        $pickerInit = ['selectedId' => $selectedItemId ? (string) $selectedItemId : '', 'fallbackQuery' => (string) ($line->code ?? '')];
        $selectedVatType = $selectedVatId ? $vatTypes->firstWhere('id', (int) $selectedVatId) : null;
    }
@endphp
@if($mode === 'form')
<form method="POST" action="{{ $action }}" class="pt-2" data-keep-scroll
      x-data="catalogueLinePicker({{ \Illuminate\Support\Js::from($catalogueItemsForJs) }})">
    @csrf
    @if($taskId)<input type="hidden" name="rental_job_card_task_id" value="{{ $taskId }}">@endif
@elseif($isEdit)
<form method="POST" action="{{ route('corex.rental-job-cards.lines.update', [$jobCard, $line]) }}" class="py-2 px-1 rounded-md" data-keep-scroll
      id="jc-edit-line-{{ $line->id }}" style="background: var(--surface-2, rgba(0,0,0,.03));"
      x-data="catalogueLinePicker(window.jcCatalogueItems || [], {{ \Illuminate\Support\Js::from($pickerInit) }})">
    @csrf
    @method('PUT')
    <input type="hidden" name="_edit_line_id" value="{{ $line->id }}">
@else
<div class="pt-2" x-data="catalogueLinePicker({{ \Illuminate\Support\Js::from($catalogueItemsForJs) }})">
@endif
    <div style="{{ $gridStyle }}" class="relative">
        <div class="relative" style="min-width:0;">
            <input type="text" x-model="query" x-ref="itemInput"
                   @focus="show()" @click="show()" @input="onType()"
                   @keydown.escape="open = false" @keydown.tab="open = false"
                   @keydown.down.prevent="moveSelection(1)" @keydown.up.prevent="moveSelection(-1)"
                   @keydown.enter.prevent="pickHighlighted()"
                   role="combobox" aria-autocomplete="list" :aria-expanded="open ? 'true' : 'false'" :aria-controls="uid + '-list'"
                   :aria-activedescendant="open && highlighted >= 0 ? uid + '-opt-' + highlighted : null"
                   aria-label="Item" title="Item — search by code or description" placeholder="Search item…" autocomplete="off"
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            <input type="hidden" {{ $named ? 'name=rental_catalogue_item_id' : 'x-ref=' . $refPrefix . 'CatalogueItem' }} x-model="selectedId">
            <template x-teleport="body">
                {{-- NB: Alpine's STRING :style REPLACES the element's static style attribute, so the list's
                     whole look (dropStyle, built in catalogueLinePicker().place()) lives in the bound value, and
                     the rows use the OBJECT form, which merges with their static style. --}}
                <div x-show="open" x-cloak x-init="listEl = $el" :id="uid + '-list'" role="listbox" :style="dropStyle"
                     class="text-xs">
                    <template x-for="(it, idx) in filtered()" :key="it.id">
                        <div @mousedown.prevent="pick(it)" @mouseenter="highlighted = idx"
                             :id="uid + '-opt-' + idx" role="option" :aria-selected="idx === highlighted ? 'true' : 'false'"
                             :data-active="idx === highlighted ? '1' : null" :title="it.label"
                             :class="idx === highlighted ? 'font-semibold' : ''"
                             style="padding: 6px 10px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"
                             :style="{ backgroundColor: idx === highlighted ? 'var(--surface-2, rgba(0,0,0,.08))' : '' }"
                             x-text="it.label"></div>
                    </template>
                    <div x-show="filtered().length === 0" style="padding: 6px 10px; color: var(--text-muted);">No match — free text below</div>
                    <div @mousedown.prevent="clear()"
                         style="padding: 6px 10px; cursor: pointer; border-top: 1px solid var(--border); color: var(--text-muted);">— Free text —</div>
                </div>
            </template>
        </div>
        <input type="text" {{ $named ? 'name=description' : 'x-ref=' . $refPrefix . 'Desc' }}
               maxlength="255" placeholder="Description" aria-label="Description"
               @if($isEdit) value="{{ $val('description', $line->description) }}" required @endif
               class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
        <select {{ $named ? 'name=type' : 'x-ref=' . $refPrefix . 'Type' }}
                aria-label="Type" title="Type"
                class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            @php $typeMatched = false; @endphp
            @forelse($catalogueItemTypes as $catType)
                @php $typeSel = $isEdit && !$typeMatched && $catType->kind === $selectedType; $typeMatched = $typeMatched || $typeSel; @endphp
                <option value="{{ $catType->kind }}" @selected($typeSel)>{{ $catType->name }}</option>
            @empty
                <option value="labour" @selected($isEdit && $selectedType === 'labour')>Labour</option>
                <option value="part" @selected($isEdit && $selectedType === 'part')>Part</option>
            @endforelse
        </select>
        @if($pricesOn)
            <select {{ $named ? 'name=unit' : 'x-ref=' . $refPrefix . 'Unit' }}
                    aria-label="Unit" title="Unit"
                    class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
                <option value="">—</option>
                @foreach($catalogueUnits as $unit)
                    <option value="{{ $unit->name }}" @selected($isEdit && $selectedUnit === $unit->name)>{{ $unit->name }}</option>
                @endforeach
                @if($isEdit && $selectedUnit && !$catalogueUnits->contains('name', $selectedUnit))
                    {{-- A unit since archived/renamed in the catalogue — still the line's own saved value. --}}
                    <option value="{{ $selectedUnit }}" selected>{{ $selectedUnit }}</option>
                @endif
            </select>
            <input type="number" {{ $named ? 'name=quantity' : 'x-ref=' . $refPrefix . 'Qty' }}
                   step="0.01" min="0.01" value="{{ $isEdit ? $qtyValue : 1 }}" aria-label="Qty" title="Qty"
                   @if($isEdit) required @endif
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            @if($canEditCost)
            <input type="number" {{ $named ? 'name=unit_cost' : 'x-ref=' . $refPrefix . 'UnitCost' }}
                   step="0.01" min="0" aria-label="Cost" title="Cost (R) — what this line cost the agency"
                   @if($isEdit) value="{{ $costValue }}" @endif placeholder="cost"
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            @elseif($showCost)
            <span></span>
            @endif
            @if($canPrice)
            <input type="number" {{ $named ? 'name=unit_price' : 'x-ref=' . $refPrefix . 'UnitPrice' }}
                   step="0.01" min="0" aria-label="Selling price" title="Selling price (R) — what the owner is charged. Leave blank to price it automatically from the cost."
                   @if($isEdit) value="{{ $priceValue }}" @endif placeholder="auto"
                   class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}">
            @else
            <span></span>
            @endif
            @if($vatRegistered)
            <select {{ $named ? 'name=rental_vat_type_id' : 'x-ref=' . $refPrefix . 'VatType' }}
                    aria-label="VAT type" title="VAT type"
                    class="w-full rounded-md px-2 py-1.5 text-xs" style="{{ $fieldStyle }}"
                    @if($named)
                        onchange="this.form.custom_vat_rate.classList.toggle('hidden', this.options[this.selectedIndex].dataset.custom !== '1')"
                    @endif
            >
                @foreach($vatTypes as $vt)
                    <option value="{{ $vt->id }}" data-custom="{{ $vt->rate_mode === 'custom_per_line' ? '1' : '0' }}"
                            @if($isEdit) @selected((int) $selectedVatId === $vt->id) @else {{ $vt->is_default ? 'selected' : '' }} @endif>{{ $vt->shortLabel() }}</option>
                @endforeach
                @if($isEdit && $selectedVatId && !$selectedVatType)
                    {{-- A VAT type since archived — still this line's own saved choice. --}}
                    <option value="{{ $selectedVatId }}" data-custom="{{ $line->vatType?->rate_mode === 'custom_per_line' ? '1' : '0' }}" selected>{{ $line->vatType?->shortLabel() ?? 'Saved VAT type' }}</option>
                @endif
            </select>
            @if($named)
            @php $customVisible = $isEdit && ($selectedVatType ?? $line->vatType)?->rate_mode === 'custom_per_line'; @endphp
            <input type="number" name="custom_vat_rate" step="0.01" min="0" max="100" placeholder="Rate %"
                   @if($isEdit) value="{{ $customRate }}" @endif
                   class="w-full rounded-md px-2 py-1.5 text-xs mt-1 {{ $customVisible ? '' : 'hidden' }}" style="border: 1px solid var(--border); grid-column: 1 / -1;">
            @endif
            @endif
            @if($showCost)
            <span></span>
            @endif
        @endif
        @if($isEdit)
            <span></span>
        @else
            <button type="{{ $mode === 'form' ? 'submit' : 'button' }}"
                    @if($mode === 'draft') @click="{{ $addLineCall }}" @endif
                    aria-label="Add line" title="Add line"
                    style="{{ \App\Support\RentalJobCardLineGrid::iconButtonStyle('var(--brand-icon, #0ea5e9)', true) }} justify-self:end;">+</button>
        @endif
    </div>
@if($isEdit)
    @if($canPrice)
    {{-- §17.4.4 — markup on THIS line (a % or a set amount on top of the cost) and "Back to automatic"
         (drops the typed price and this line's markup so the card / agency rules apply again). --}}
    <div class="flex items-center gap-2 pt-2 flex-wrap text-xs" style="color: var(--text-muted);" data-line-markup>
        <span>Markup on this line</span>
        <select name="markup_type" aria-label="Markup type" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border);">
            <option value="">— none —</option>
            <option value="percent" @selected($markupTypeValue === 'percent')>% of cost</option>
            <option value="amount" @selected($markupTypeValue === 'amount')>R on the line</option>
        </select>
        <input type="number" name="markup_value" step="0.01" min="0" value="{{ $markupAmountValue }}" aria-label="Markup value" placeholder="0" class="rounded-md px-2 py-1 text-xs" style="border: 1px solid var(--border); width: 80px;">
        <label class="inline-flex items-center gap-1"><input type="checkbox" name="back_to_auto" value="1"> Back to automatic</label>
    </div>
    @endif
    <div class="flex items-center justify-end gap-2 pt-2 flex-wrap">
        @if($oldTarget)
            <span class="text-xs mr-auto" style="color: var(--ds-crimson);">
                @foreach($errors->all() as $error){{ $loop->first ? '' : ' · ' }}{{ $error }}@endforeach
            </span>
        @endif
        <button type="button" @click="editing = false" class="corex-btn-outline text-xs">Cancel</button>
        <button type="submit" class="corex-btn-primary text-xs">Save</button>
    </div>
</form>
@elseif($mode === 'form')
</form>
@else
</div>
@endif
