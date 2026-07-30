{{--
    AT-350 — shared capture fields for the create form and the enrich form, so the
    two can never drift into offering different inputs.
    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — tokens only.

    Expects: $record (PropertyThirdPartySale|null), $tpsReasons (array)
--}}

@php
    $tpsInput = 'width:100%; background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);';
    $tpsLabel = 'display:block; margin-bottom:0.25rem; color:var(--text-secondary);';
@endphp

<div>
    <label class="text-[11px] font-semibold uppercase tracking-wider" style="{{ $tpsLabel }}" for="tps_sold_by_agency">Which agency sold it?</label>
    <input type="text" id="tps_sold_by_agency" name="sold_by_agency" maxlength="200"
           value="{{ old('sold_by_agency', $record?->sold_by_agency) }}"
           class="rounded-md px-3 py-2 text-sm" style="{{ $tpsInput }}">
    @error('sold_by_agency')
        <p class="mt-1 text-xs" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>
    @enderror
</div>

<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="text-[11px] font-semibold uppercase tracking-wider" style="{{ $tpsLabel }}" for="tps_sold_price">Sold price (R)</label>
        <input type="number" id="tps_sold_price" name="sold_price" min="0" step="1"
               value="{{ old('sold_price', $record?->sold_price ? (int) $record->sold_price : '') }}"
               placeholder="e.g. 2150000"
               class="rounded-md px-3 py-2 text-sm" style="{{ $tpsInput }}">
        @error('sold_price')
            <p class="mt-1 text-xs" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>
        @enderror
    </div>
    <div>
        <label class="text-[11px] font-semibold uppercase tracking-wider" style="{{ $tpsLabel }}" for="tps_sold_date">Sold date</label>
        <input type="date" id="tps_sold_date" name="sold_date" max="{{ now()->toDateString() }}"
               value="{{ old('sold_date', $record?->sold_date?->toDateString()) }}"
               class="rounded-md px-3 py-2 text-sm" style="{{ $tpsInput }} color-scheme: light dark;">
        @error('sold_date')
            <p class="mt-1 text-xs" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>
        @enderror
    </div>
</div>

{{-- Price + date together are what turn this loss into a comparable sale for
     CMAs. Said out loud so the agent knows why it is worth chasing the number. --}}
<p class="text-xs" style="color:var(--text-muted);">
    Price and date together make this a comparable sale for CMAs and suburb intelligence.
</p>

<div>
    <label class="text-[11px] font-semibold uppercase tracking-wider" style="{{ $tpsLabel }}" for="tps_loss_reason">Why did we lose it?</label>
    <select id="tps_loss_reason" name="loss_reason" class="rounded-md px-3 py-2 text-sm" style="{{ $tpsInput }}">
        <option value="">— Not recorded —</option>
        @foreach($tpsReasons as $value => $label)
            <option value="{{ $value }}" {{ old('loss_reason', $record?->loss_reason) === $value ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
    </select>
    @error('loss_reason')
        <p class="mt-1 text-xs" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>
    @enderror
</div>

<div>
    <label class="text-[11px] font-semibold uppercase tracking-wider" style="{{ $tpsLabel }}" for="tps_notes">Notes</label>
    <textarea id="tps_notes" name="notes" rows="3" maxlength="2000"
              placeholder="Anything worth remembering next time we pitch this street"
              class="rounded-md px-3 py-2 text-sm" style="{{ $tpsInput }}">{{ old('notes', $record?->notes) }}</textarea>
    @error('notes')
        <p class="mt-1 text-xs" style="color:var(--ds-crimson, #c41e3a);">{{ $message }}</p>
    @enderror
</div>
