{{-- Guide price, reserve disclosure and result for one lot. --}}
@php
    $resultLabels = [
        'sold' => ['Sold', 'bg-green-100 text-green-800'],
        'sold_subject_to_confirmation' => ['Sold — subject to seller confirmation', 'bg-amber-100 text-amber-800'],
        'passed_in' => ['Passed in — contact the agent to negotiate', 'bg-blue-100 text-blue-800'],
        'withdrawn' => ['Withdrawn', 'bg-slate-200 text-slate-700'],
    ];
    $result = $resultLabels[$lot->status] ?? null;
@endphp
@if($result)<span class="inline-block text-xs font-semibold rounded px-2 py-1 {{ $result[1] }}">{{ $result[0] }}</span>@endif
@if($guideEnabled && ($lot->guide_price_min || $lot->guide_price_max))
<div class="text-lg font-bold">Guide price: R {{ number_format((float) ($lot->guide_price_min ?: $lot->guide_price_max), 0, '.', ',') }}@if($lot->guide_price_min && $lot->guide_price_max && $lot->guide_price_max != $lot->guide_price_min) – R {{ number_format((float) $lot->guide_price_max, 0, '.', ',') }}@endif</div>
@endif
@if($lot->opening_bid)<div class="text-sm text-slate-600">Opening bid: R {{ number_format((float) $lot->opening_bid, 0, '.', ',') }}</div>@endif
<div class="text-sm text-slate-600">
    @if($lot->reserve_price === null)
        Reserve status will be confirmed by the auctioneer.
    @elseif((float) $lot->reserve_price <= 0)
        Offered <strong>without reserve</strong>.
    @else
        <strong>Subject to a reserve price</strong>@if($showReserveAmount): R {{ number_format((float) $lot->reserve_price, 0, '.', ',') }}@endif.
    @endif
</div>
