{{-- Auction tab on the property page — the auction + lot this property is linked to. --}}
@php
    $ai = \App\Services\Auctions\PropertyAuctionInfo::for($property, true);
    $row = fn ($label, $value) => '<div><div class="text-xs uppercase tracking-wider" style="color:var(--text-muted);">'.e($label).'</div><div class="text-sm font-medium mt-0.5" style="color:var(--text-primary);">'.$value.'</div></div>';
@endphp
@if(! $ai)
    <div class="rounded-md px-4 py-10 text-center text-sm" style="background:var(--surface);border:1px solid var(--border);color:var(--text-muted);">
        This property is marked as on auction but isn't on an auction's lot list yet.
        @permission('auctions.create')
        <div class="mt-3"><a href="{{ route('corex.auctions.index') }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">Open the Auction Diary</a></div>
        @endpermission
    </div>
@else
    @php $auction = $ai['auction']; $lot = $ai['lot']; @endphp
    <div class="flex flex-wrap items-center justify-between gap-3" data-tour="prop-auction-header">
        <div>
            <h2 class="text-base font-bold" style="color:var(--text-primary);">{{ $auction->title }}</h2>
            <p class="text-xs" style="color:var(--text-muted);">Lot {{ $lot->lot_number }} · {{ ucwords(str_replace('_', ' ', $lot->status)) }} · Auction {{ ucwords(str_replace('_', ' ', $auction->status)) }}</p>
        </div>
        <div class="flex flex-wrap gap-2">
            @permission('auctions.view')
            <a href="{{ route('corex.auctions.show', $auction) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">Open auction</a>
            <a href="{{ route('corex.auctions.lots.show', $lot) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">Open lot</a>
            @endpermission
            @if($ai['publicUrl'])
            <a href="{{ $ai['publicUrl'] }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">View public advert</a>
            @endif
        </div>
    </div>

    <div data-tour="prop-auction-details" class="grid grid-cols-2 lg:grid-cols-4 gap-4 rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
        {!! $row('Auction date', e($auction->starts_at?->format('D, d M Y \a\t H:i') ?? '—')) !!}
        {!! $row('Venue', e(trim(($auction->venue_name ?? '').($auction->venue_name && $auction->venue_address ? ', ' : '').($auction->venue_address ?? '')) ?: '—')) !!}
        {!! $row('Bidding', e(['in_room' => 'In-room', 'online' => 'Online', 'hybrid' => 'In-room and online'][$auction->bidding_mode] ?? '—')) !!}
        {!! $row('Conducted by', e($auction->isInternal() ? ($auction->auctioneerUser?->name ?? '—') : ($auction->auctioneer_company ?: '—'))) !!}
        {!! $row('Licence no.', e($auction->auctioneer_licence_no ?: '—')) !!}
        {!! $row('Auctioneer phone', e($auction->auctioneer_phone ?: '—')) !!}
        {!! $row('Auctioneer email', e($auction->auctioneer_email ?: '—')) !!}
        {!! $row('Registration', e($auction->registration_opens_at ? $auction->registration_opens_at->format('d M, H:i').' – '.($auction->registration_closes_at?->format('d M, H:i') ?? '—') : ($auction->registration_closes_at ? 'Closes '.$auction->registration_closes_at->format('d M, H:i') : '—'))) !!}
    </div>

    <div data-tour="prop-auction-price" class="grid grid-cols-2 lg:grid-cols-4 gap-4 rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
        {!! $row('Reserve', $ai['showReserve'] ? e($lot->reserve_price !== null ? ((float) $lot->reserve_price > 0 ? 'R '.number_format((float) $lot->reserve_price, 0, '.', ' ') : 'No reserve') : 'Not stated') : 'Hidden') !!}
        {!! $row('Guide price', e(($lot->guide_price_min || $lot->guide_price_max) ? 'R '.number_format((float) ($lot->guide_price_min ?: $lot->guide_price_max), 0, '.', ' ').(($lot->guide_price_min && $lot->guide_price_max && $lot->guide_price_max != $lot->guide_price_min) ? ' – R '.number_format((float) $lot->guide_price_max, 0, '.', ' ') : '') : '—').($ai['guideEnabled'] ? '' : ' <span class="text-xs" style="color:var(--text-muted);">(not shown publicly)</span>')) !!}
        {!! $row('Opening bid', e($lot->opening_bid ? 'R '.number_format((float) $lot->opening_bid, 0, '.', ' ') : '—')) !!}
        {!! $row('Result', e($lot->hammer_price ? 'R '.number_format((float) $lot->hammer_price, 0, '.', ' ') : '—')) !!}
    </div>

    @if($auction->rules_file_path || $auction->conditions_file_path)
    <div data-tour="prop-auction-docs" class="rounded-md p-4 flex flex-wrap items-center gap-3" style="background:var(--surface);border:1px solid var(--border);">
        <span class="text-sm font-bold" style="color:var(--text-primary);">Documents</span>
        @foreach(['rules' => ['Rules of Auction', $auction->rules_file_path], 'conditions' => ['Conditions of Sale', $auction->conditions_file_path]] as $kind => [$dt, $dp])
            @if($dp)
            @permission('auctions.view')
            <a href="{{ route('corex.auctions.document', [$auction, $kind]) }}" target="_blank" rel="noopener" class="corex-btn-outline text-xs">{{ $dt }} (PDF)</a>
            @endpermission
            @endif
        @endforeach
    </div>
    @endif

    <div data-tour="prop-auction-viewings" class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);">
        <div class="px-4 py-3 text-sm font-bold" style="color:var(--text-primary);">Viewings</div>
        @forelse($ai['viewings'] as $v)
            <div class="px-4 py-2.5 text-sm flex flex-wrap justify-between gap-2" style="border-top:1px solid var(--border);color:var(--text-primary);">
                <span>{{ $v->starts_at->format('D, d M Y H:i') }} – {{ $v->ends_at->format('H:i') }}@if($v->is_by_appointment) · by appointment @endif</span>
                <span style="color:var(--text-muted);">{{ $v->agent?->name }} {{ $v->notes }}</span>
            </div>
        @empty
            <div class="px-4 py-5 text-center text-sm" style="border-top:1px solid var(--border);color:var(--text-muted);">No upcoming viewings.</div>
        @endforelse
    </div>
@endif
