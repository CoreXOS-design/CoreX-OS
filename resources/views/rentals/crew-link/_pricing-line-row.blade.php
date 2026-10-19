{{--
    §17.43 - ONE Parts & labour row, shared by the always-open blank row and every saved draft (the old "Add a part or labour" panel and
    "Change this line" panel are gone).

    Part   : the item or description, how many, "what it cost you, each (R)" (pre-filled from the list), the receipt camera.
    Labour : the item or description and HOURS - nothing about money. The crew never types, sees or is told a labour rate or amount.
    A picked list item decides Part or Labour, the unit and the cost on file - none of those is asked again (the Part/Labour choice
    only shows while no item is picked, because free-typed labour exists). Unit, note, photos and the "extra" tick sit behind "+ more".

    Works with JavaScript off (nothing is browser-`required`; the server speaks plain language): the button posts the row as a normal
    form. With JavaScript on, the script in _block-pricing saves the row by fetch, with no reload.

    Receives $l (the saved row from CrewPricingBlock, or null for the blank row), $block, $pricesOn, $actions.
--}}
@php
    $isLabour = $l && $l['kind'] === 'labour';
    $hasItem = $l && ! empty($l['item_id']);
    $lineUrl = fn ($key, $id) => str_replace('__LINE__', $id, $actions[$key] ?? '');
@endphp
<form method="POST" enctype="multipart/form-data" class="cj-line-form cj-entry {{ $l ? 'is-saved' : 'is-new' }}"
      action="{{ $l ? $lineUrl('line_update', $l['id']) : $actions['line_add'] }}"
      data-add-url="{{ $actions['line_add'] }}" data-update-url="{{ $actions['line_update'] ?? '' }}"
      data-archive-url="{{ $actions['line_archive'] ?? '' }}" data-restore-url="{{ $actions['line_restore'] ?? '' }}"
      @if($l) data-line-id="{{ $l['id'] }}" @endif>
    @csrf
    @if(count($block['catalogue']))
        <label class="cj-label">From the list <span class="cj-muted">(optional)</span></label>
        <select name="rental_catalogue_item_id" class="cj-cat-pick" aria-label="Pick from the list">
            <option value="">— type it yourself —</option>
            @foreach($block['catalogue'] as $c)
                <option value="{{ $c['id'] }}" data-desc="{{ $c['description'] }}" data-kind="{{ $c['kind'] }}" data-unit="{{ $c['unit'] }}" @if($pricesOn && $c['cost'] !== null) data-cost="{{ $c['cost'] }}" @endif @selected($hasItem && (int) $l['item_id'] === (int) $c['id'])>{{ $c['label'] }}</option>
            @endforeach
        </select>
    @endif
    <label class="cj-label">What is it</label>
    <input type="text" name="description" class="cj-desc" value="{{ $l['description'] ?? '' }}" maxlength="255" autocomplete="off" placeholder="e.g. geyser element">
    <div class="cj-kind-wrap" @if($hasItem) hidden @endif>
        <label class="cj-label">Part or labour</label>
        <select name="type" class="cj-kind"><option value="part" @selected(! $isLabour)>Part</option><option value="labour" @selected($isLabour)>Labour</option></select>
    </div>
    <div class="cj-nums">
        <div class="cj-num">
            <label class="cj-label cj-qty-label" data-part-label="How many" data-labour-label="Hours">{{ $isLabour ? 'Hours' : 'How many' }}</label>
            <input type="number" name="quantity" class="cj-qty" value="{{ $l['quantity'] ?? '1' }}" step="0.01" min="0.01" inputmode="decimal" onfocus="this.dataset.fresh=1;this.select()" onmouseup="if(this.dataset.fresh){event.preventDefault();delete this.dataset.fresh}">
        </div>
        @if($pricesOn)
            <div class="cj-num cj-part-only" @if($isLabour) hidden @endif>
                <label class="cj-label">Cost each (R)</label>
                <input type="number" name="unit_cost" class="cj-cost" value="{{ $isLabour ? '' : ($l['unit_cost'] ?? '') }}" step="0.01" min="0" inputmode="decimal" onfocus="this.dataset.fresh=1;this.select()" onmouseup="if(this.dataset.fresh){event.preventDefault();delete this.dataset.fresh}" @if($isLabour) disabled @endif>
            </div>
        @endif
    </div>
    @if($pricesOn)<div class="cj-muted cj-cost-hint cj-part-only" hidden>Cost filled in from the list &mdash; change it if you paid something different.</div>@endif
    <div class="cj-part-only" @if($isLabour) hidden @endif>
        <label class="cj-label">Receipt (optional)</label>
        <div class="cj-receipt" data-receipt>
            <label class="cj-btn ghost cj-filebtn"><span>Take photo</span><input type="file" name="receipt_camera" class="cj-sr" accept="image/*" capture="environment"></label>
            <label class="cj-btn ghost cj-filebtn"><span>Choose from phone</span><input type="file" name="receipt_file" class="cj-sr" accept="image/*"></label>
        </div>
    </div>
    <div class="cj-media">
        @if($l)
            @if(count($l['photos']))<div class="cj-photos">@foreach($l['photos'] as $p)<a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="" loading="lazy"></a>@endforeach</div>@endif
            @if(!empty($l['receipt_url']))<div class="cj-muted" data-receipt-added>Receipt added &middot; <a href="{{ $l['receipt_url'] }}" target="_blank" rel="noopener">view</a></div>@endif
        @endif
    </div>
    <details class="cj-more">
        <summary>+ more (unit, note, photos)</summary>
        <div class="cj-part-only" @if($isLabour) hidden @endif>
            <label class="cj-label">Unit (e.g. each, metre)</label>
            <input type="text" name="unit" class="cj-unit" value="{{ $isLabour ? '' : ($l['unit'] ?? '') }}" maxlength="30" autocomplete="off">
        </div>
        <label class="cj-label">Note (optional)</label>
        <textarea name="note" maxlength="1000" placeholder="{{ $l ? '' : 'Why is this needed?' }}">{{ $l['note'] ?? '' }}</textarea>
        <label class="cj-label">{{ $l ? 'Add photos' : 'Photos' }} (optional, up to {{ $block['photo_limit'] }}{{ $l ? ' on a line' : '' }})</label>
        <input type="file" name="photos[]" accept="image/*" @unless($l) capture="environment" @endunless multiple style="width:100%; min-height:48px; font-size:15px;">
        @if(!empty($block['request']) && ! $l)
            <label class="cj-confirm"><input type="checkbox" name="is_extra" value="1"><span>This is an extra, not part of the price you were asked for.</span></label>
        @endif
    </details>
    <div class="cj-row-error" role="alert" hidden></div>
    <div class="cj-row-foot">
        @if($l)
            <strong class="cj-row-total">@if($pricesOn && $l['cost_total'] !== null)R {{ $l['cost_total'] }}@endif</strong>
            <span class="cj-row-status cj-muted" aria-live="polite">On your list — not sent yet</span>
            <noscript><button type="submit" class="cj-btn primary small">Save changes</button></noscript>
            <button type="submit" formaction="{{ $lineUrl('line_archive', $l['id']) }}" formnovalidate class="cj-btn ghost small cj-remove">Remove</button>
        @else
            <button type="submit" class="cj-btn primary cj-add-new-line">Add new line</button>
        @endif
    </div>
</form>
