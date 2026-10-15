{{--
    AT-459 — the fields of ONE Parts & labour line, shared by "Add a part or labour" and "Change this line".

    Part   : how many, unit, "What it cost you, each (R)" (pre-filled from the list when an item is picked), a receipt
             ("Take photo" / "Choose from phone"), note, photos.
    Labour : HOURS (or the item's own unit — Day, Call-out), nothing else about money. The crew never types, sees or is
             told a labour rate or amount: the office prices it, or it is worked out here from the catalogue's cost rate.

    Works with JavaScript off (nothing is `required` in the browser — the server speaks plain language); the script in
    _block-pricing only swaps which fields show for Part/Labour and pre-fills a picked item.

    Receives $block, $pricesOn and $l (the existing line row for "Change", null for "Add").
--}}
@php
    $isLabour = $l && $l['kind'] === 'labour';
@endphp
<label class="cj-label">Part or labour</label>
<select name="type" class="cj-kind"><option value="part" @selected(! $isLabour)>Part</option><option value="labour" @selected($isLabour)>Labour</option></select>
<label class="cj-label">What is it</label>
<input type="text" name="description" class="cj-desc" value="{{ $l['description'] ?? '' }}" maxlength="255" autocomplete="off" placeholder="e.g. geyser element" @if($l) required @endif>
<label class="cj-label cj-qty-label" data-part-label="How many" data-labour-label="Hours">{{ $isLabour ? 'Hours' : 'How many' }}</label>
<input type="number" name="quantity" class="cj-qty" value="{{ $l['quantity'] ?? '1' }}" step="0.01" min="0.01" inputmode="decimal" required>
<div class="cj-part-only" @if($isLabour) hidden @endif>
    <label class="cj-label">Unit (e.g. each, metre)</label>
    <input type="text" name="unit" class="cj-unit" value="{{ $isLabour ? '' : ($l['unit'] ?? '') }}" maxlength="30" autocomplete="off">
    @if($pricesOn)
        <label class="cj-label">What it cost you, each (R)</label>
        <input type="number" name="unit_cost" class="cj-cost" value="{{ $isLabour ? '' : ($l['unit_cost'] ?? '') }}" step="0.01" min="0" inputmode="decimal" @if($isLabour) disabled @endif>
        <div class="cj-muted cj-cost-hint" hidden>Filled in from the list &mdash; change it if you paid something different.</div>
    @endif
    <label class="cj-label">Receipt (optional)</label>
    <div class="cj-receipt" data-receipt>
        <label class="cj-btn ghost cj-filebtn"><span>Take photo</span><input type="file" name="receipt_camera" class="cj-sr" accept="image/*" capture="environment"></label>
        <label class="cj-btn ghost cj-filebtn"><span>Choose from phone</span><input type="file" name="receipt_file" class="cj-sr" accept="image/*"></label>
    </div>
    <div class="cj-muted cj-receipt-name" aria-live="polite">
        @if($l && ! empty($l['receipt_url']))
            Receipt added &mdash; <a href="{{ $l['receipt_url'] }}" target="_blank" rel="noopener">view</a>. Add another to replace it.
        @endif
    </div>
</div>
<label class="cj-label">Note (optional)</label>
<textarea name="note" maxlength="1000" placeholder="{{ $l ? '' : 'Why is this needed?' }}">{{ $l['note'] ?? '' }}</textarea>
<label class="cj-label">{{ $l ? 'Add photos' : 'Photos' }} ({{ $l ? '' : 'optional, ' }}up to {{ $block['photo_limit'] }}{{ $l ? ' on a line' : '' }})</label>
<input type="file" name="photos[]" accept="image/*" @unless($l) capture="environment" @endunless multiple style="width:100%; min-height:48px; font-size:15px;">
