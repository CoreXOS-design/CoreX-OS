{{-- AT-432 — Auctions → Properties lens only: which lot / auction this property sits in. --}}
@php
    $chipLot = $property->auctionLots->sortBy(fn ($l) => $l->isConcluded() ? 1 : 0)->first();
@endphp
@if($chipLot)
<div class="text-[11px] mt-1.5 inline-flex flex-wrap items-center gap-x-2 gap-y-0.5 px-2 py-1 rounded-md w-fit"
     style="background:color-mix(in srgb, var(--ds-amber) 12%, transparent); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 35%, transparent);">
    <a href="{{ route('corex.auctions.lots.show', $chipLot) }}" class="font-semibold" style="color:inherit;">Lot {{ $chipLot->lot_number }}</a>
    @if($chipLot->auction)
        <span>{{ $chipLot->auction->reference }} · {{ $chipLot->auction->starts_at?->format('d M Y') }}</span>
    @endif
    <span class="font-semibold">{{ $chipLot->statusLabel() }}</span>
    @if($chipLot->guide_price_min || $chipLot->guide_price_max)
        <span>Guide R {{ number_format((float) ($chipLot->guide_price_min ?: $chipLot->guide_price_max), 0, '.', ' ') }}</span>
    @endif
</div>
@endif
