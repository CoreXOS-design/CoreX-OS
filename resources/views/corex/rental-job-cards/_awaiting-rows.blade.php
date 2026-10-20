{{--
    AT-460 - the lines the crew sent that the office has not accepted yet, shown INLINE in the lines table (same grid, same columns) with Accept / Reject on the row.
    They are in NO total, quote, owner payload or tenant payload until accepted (rental-work-orders.md §17.5.3). Same routes and fields as the old separate block:
    Accept = unit_cost (for someone who may see costs) + unit_price; Reject = reason. The cost / selling boxes sit in their grid columns and belong to the Accept form
    through the `form` attribute.
    Receives $rows (Collection<RentalJobCardLine>) and what show.blade.php has in scope: $jobCard, $isOpen, $pricesOn, $vat, $canPrice, $canViewCosts, $awaitingPhotos, $receipts.
--}}
@php
    $awGrid = \App\Support\RentalJobCardLineGrid::gridStyle($pricesOn, $vat['registered'], $canViewCosts ?? false);
    $awCell = \App\Support\RentalJobCardLineGrid::cellStyle();
    $awMoney = fn ($v) => 'R' . number_format((float) $v, 2);
    $awShowCost = ($canViewCosts ?? false) && $pricesOn;
@endphp
<div data-crew-awaiting>
@foreach($rows as $l)
    <div class="jc-await" data-awaiting-line="{{ $l->id }}">
        <div style="{{ $awGrid }}" class="py-1 text-xs">
            {{-- 19 Oct 2026: same compact tracks as the saved lines - Description (with a small line under it), Qty, [Cost], Selling, Total, actions --}}
            <span style="min-width:0; overflow-wrap:anywhere; {{ $awCell }}"><strong>{{ $l->description }}</strong>@if($l->origin === 'crew_extra') <span style="color: var(--text-muted);">· extra</span>@endif
                @if(($receipts ?? collect())->has($l->id) && $l->type === 'part') @include('corex.rental-job-cards._receipt-thumb', ['receipt' => $receipts[$l->id], 'label' => $l->description])@endif
                <span style="display:block; font-size:10px; color: var(--text-muted);">crew · {{ ucfirst($l->type) }}@if($pricesOn && $l->unit) · {{ $l->unit }}@endif</span></span>
            @if($pricesOn)
                <span class="truncate" style="{{ $awCell }} text-align:right; padding-right:9px;">{{ rtrim(rtrim(number_format((float) $l->quantity, 2), '0'), '.') }}</span>
                @if($awShowCost)
                    @if($canPrice && $isOpen)
                        <input type="number" form="jc-accept-{{ $l->id }}" name="unit_cost" step="0.01" min="0" value="{{ $l->unit_cost !== null ? number_format((float) $l->unit_cost, 2, '.', '') : '' }}" placeholder="cost" aria-label="Cost each" title="Cost each" class="w-full rounded-md px-2 text-xs" style="{{ \App\Support\RentalJobCardLineGrid::amountFieldStyle() }}">
                    @else
                        <span class="truncate" style="{{ $awCell }} text-align:right; padding-right:9px;">{{ $l->unit_cost !== null ? $awMoney($l->unit_cost) : '—' }}</span>
                    @endif
                @endif
                @if($canPrice && $isOpen)
                    <input type="number" form="jc-accept-{{ $l->id }}" name="unit_price" step="0.01" min="0" placeholder="auto" aria-label="Selling each" title="Selling each - leave blank to price it by the rules" class="w-full rounded-md px-2 text-xs" style="{{ \App\Support\RentalJobCardLineGrid::amountFieldStyle() }}">
                @else
                    <span style="{{ $awCell }} text-align:right; padding-right:9px;">—</span>
                @endif
                <span></span>{{-- Total: only once accepted and priced --}}
            @endif
            <span></span>
        </div>
        <div class="jc-await-sub">
            @if($l->crew_note)<span style="color: var(--text-primary);">“{{ $l->crew_note }}”</span>@endif
            @if($l->type === 'labour' && $l->unit_cost === null)<span data-labour-unpriced>Labour — the crew counted the hours; set the cost and price when you accept.</span>@endif
            <span>{{ $l->crew_added_by_label }}{{ $l->crew_added_at ? ' · ' . $l->crew_added_at->format('Y-m-d H:i') : '' }}</span>
            @if(($receipts ?? collect())->has($l->id) && $l->type === 'part')<span class="flex items-center gap-2" data-awaiting-receipt>Receipt: @include('corex.rental-job-cards._receipt-thumb', ['receipt' => $receipts[$l->id], 'label' => $l->description])</span>@endif
            @if(($awaitingPhotos[$l->id] ?? collect())->isNotEmpty())
                <span class="flex gap-1 flex-wrap">@foreach($awaitingPhotos[$l->id] as $ph)<a href="{{ $ph->displayUrl() }}" target="_blank"><img src="{{ $ph->displayUrl() }}" class="rounded" style="width:40px; height:40px; object-fit:cover;" alt=""></a>@endforeach</span>
            @endif
        </div>
        @if($canPrice && $isOpen)
        <div class="flex flex-wrap items-center gap-2 pt-2">
            <form id="jc-accept-{{ $l->id }}" data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-lines.accept', [$jobCard, $l->id]) }}">
                @csrf
                <button type="submit" class="corex-btn-primary text-xs">Accept</button>
            </form>
            <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.crew-lines.reject', [$jobCard, $l->id]) }}" class="flex flex-1 items-center gap-2" style="min-width: 220px;">
                @csrf
                <input type="text" name="reason" required maxlength="1000" placeholder="Why not? (the crew will see this)" class="flex-1 rounded-md px-2 text-xs" style="border: 1px solid var(--border); height: 30px; min-width: 0;">
                <button type="submit" class="corex-btn-outline text-xs" style="color: var(--ds-crimson);">Reject</button>
            </form>
        </div>
        @else
            <div class="text-xs pt-1" style="color: var(--text-muted);">Someone who can price job cards needs to accept or reject this line.</div>
        @endif
    </div>
@endforeach
</div>
