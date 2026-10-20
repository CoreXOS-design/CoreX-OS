{{--
    AT-460 - the totals, one block under the lines table: Cost (excl) · Markup / margin · Subtotal (excl) · VAT · Total (incl). Cost and margin exist in the page only for
    `rental_job_cards.view_costs` (absent, not hidden). The bulk percentage markup (All / Parts / Labour %) is a small "Apply markup..." link here that opens its row
    inline - same route (corex.rental-job-cards.markup), same fields - instead of a card of its own. The owner-limit sentence is NOT here: it lives in Money & approval, once.
--}}
@if($pricesOn)
@php
    $ttCompare = $vat['registered'] ? (float) $vat['totalIncl'] : (float) ($jobCard->total_amount ?? 0);
    $ttMarkupVal = fn ($v) => old($v, $jobCard->{$v} !== null ? rtrim(rtrim(number_format((float) $jobCard->{$v}, 2, '.', ''), '0'), '.') : '');
@endphp
<div class="jc-totals" x-data="{ markup: {{ $errors->has('pricing') ? 'true' : 'false' }} }" data-totals>
    <div style="min-width: 0; max-width: 100%;">
        <dl>
            @if(($canViewCosts ?? false) && $margin)
                <div data-cost-margin-totals style="display: contents;">
                <dt>Cost{{ $costVat && $costVat['registered'] ? ' (excl)' : '' }}</dt>
                <dd>{{ $margin['marginableLines'] > 0 || $margin['costExcl'] > 0 ? 'R' . number_format($margin['costExcl'], 2) : '—' }}</dd>
                <dt>Markup / margin (excl)</dt>
                <dd>{{ $margin['marginableLines'] > 0 ? 'R' . number_format($margin['marginExcl'], 2) . ($margin['marginPct'] !== null ? ' (' . $margin['marginPct'] . ' %)' : '') : '— no cost recorded' }}</dd>
                </div>
            @endif
            @if($vat['registered'])
                <dt>Subtotal (excl VAT)</dt><dd>R{{ number_format((float) $vat['subtotalExcl'], 2) }}</dd>
                @foreach($vat['groups'] as $group)
                    <dt style="color: var(--text-muted);">{{ $group['label'] }}</dt><dd style="color: var(--text-muted);">R{{ number_format((float) $group['amount'], 2) }}</dd>
                @endforeach
            @endif
            <dt class="jc-total-final">{{ $vat['registered'] ? 'Total (incl VAT)' : 'Total' }}</dt>
            <dd class="jc-total-final">R{{ number_format($ttCompare, 2) }}</dd>
        </dl>
        @if(($canViewCosts ?? false) && $margin && $margin['linesWithoutCost'] > 0)
            <div class="text-xs pt-1" style="color: var(--ds-crimson); text-align: right;" data-lines-without-cost>{{ $margin['linesWithoutCost'] }} {{ $margin['linesWithoutCost'] === 1 ? 'line has' : 'lines have' }} no cost recorded — the margin above covers the other lines only.</div>
        @endif

        @if(($canPrice ?? false) && $isOpen)
            <div class="pt-2" style="text-align: right;">
                <button type="button" class="text-xs underline" style="color: var(--brand-icon, #0ea5e9);" @click="markup = !markup" :aria-expanded="markup ? 'true' : 'false'" data-markup-open>Apply markup…</button>
            </div>
            @if($errors->has('pricing'))
                <div class="text-xs p-2 rounded mt-1" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); color: var(--ds-crimson);">{{ $errors->first('pricing') }}</div>
            @endif
            {{-- §17.4.4 - card-level markup. The crew works on cost; these boxes decide what the owner is charged. A line priced by hand, or given its own markup, is left alone. --}}
            <form x-show="markup" x-cloak data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.markup', $jobCard) }}" class="pt-2 flex flex-wrap items-end justify-end gap-2" data-markup-form>
                @csrf
                <label class="text-xs space-y-1"><span style="color: var(--text-muted); display:block;">All lines %</span>
                    <input type="number" name="markup_all_percent" step="0.01" min="0" max="1000" value="{{ $ttMarkupVal('markup_all_percent') }}" class="rounded-md px-2 text-xs" style="border: 1px solid var(--border); width: 84px; height: 30px;"></label>
                <label class="text-xs space-y-1"><span style="color: var(--text-muted); display:block;">Parts %</span>
                    <input type="number" name="markup_parts_percent" step="0.01" min="0" max="1000" value="{{ $ttMarkupVal('markup_parts_percent') }}" class="rounded-md px-2 text-xs" style="border: 1px solid var(--border); width: 84px; height: 30px;"></label>
                <label class="text-xs space-y-1"><span style="color: var(--text-muted); display:block;">Labour %</span>
                    <input type="number" name="markup_labour_percent" step="0.01" min="0" max="1000" value="{{ $ttMarkupVal('markup_labour_percent') }}" class="rounded-md px-2 text-xs" style="border: 1px solid var(--border); width: 84px; height: 30px;"></label>
                <button type="submit" class="corex-btn-outline text-xs">Apply</button>
            </form>
        @endif
    </div>
</div>
@endif
