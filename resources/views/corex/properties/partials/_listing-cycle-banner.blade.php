{{-- Sold stock (AT-512, .ai/specs/sold-stock.md section 2D): a re-prospected Sold property is on its next listing
     cycle. Shows the history pointer and, while pending, the previous buyer as the suggested new seller. --}}
@php
    $cycleNo = (int) ($property->listing_cycle ?? 1);
    $cycleSuggestion = $cycleNo > 1 ? app(\App\Services\SoldStock\SoldStockService::class)->openSuggestion($property) : null;
    $cycleSuggested = $cycleSuggestion ? \App\Models\Contact::find($cycleSuggestion->suggested_seller_contact_id) : null;
    $lastSale = $cycleNo > 1 ? \App\Models\SoldStock::where('property_id', $property->id)->orderByDesc('cycle_no')->first() : null;
@endphp
@if($cycleNo > 1)
    <div class="rounded-md px-4 py-3 mb-4" data-listing-cycle-banner
         style="background: color-mix(in srgb, var(--brand-button, #0ea5e9) 10%, transparent); border:1px solid color-mix(in srgb, var(--brand-button, #0ea5e9) 40%, var(--border));">
        <div class="text-sm font-semibold" style="color: var(--text-primary);">Listing cycle {{ $cycleNo }} - this property was sold before</div>
        <div class="text-xs mt-1" style="color: var(--text-muted);">
            The earlier sale is kept as history.
            @if($lastSale)<a href="{{ route('corex.sold-stock.show', $lastSale->id) }}" style="color:var(--brand-button,#0ea5e9);">View it in Sold stock</a>.@endif
            The previous owner is no longer linked to this property.
        </div>
        @if($cycleSuggested)
            <div class="mt-2 flex items-center gap-3 flex-wrap">
                <span class="text-sm" style="color: var(--text-primary);">Suggested new seller: <strong>{{ $cycleSuggested->full_name }}</strong> (bought it last time)</span>
                <form method="POST" action="{{ route('corex.properties.cycle.confirm', $property->id) }}">@csrf
                    <button type="submit" class="corex-btn-primary text-xs px-3 py-1.5">Confirm as seller</button></form>
                <form method="POST" action="{{ route('corex.properties.cycle.dismiss', $property->id) }}">@csrf
                    <button type="submit" class="text-xs" style="color:var(--text-muted);">Not them</button></form>
            </div>
        @endif
    </div>
@endif
