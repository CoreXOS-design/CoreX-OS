{{-- Auction details block for the live preview (public-facing look). Mirrors the public advert's rules. --}}
@php
    $ai = \App\Services\Auctions\PropertyAuctionInfo::for($property, false);
    $money = fn ($v) => 'R '.number_format((float) $v, 0, '.', ' ');
@endphp
@if($ai)
    @php
        $auction = $ai['auction']; $lot = $ai['lot'];
        $rows = [];
        $rows[] = ['Auction date', $auction->starts_at?->format('l, d F Y \a\t H:i')];
        $venue = trim(($auction->venue_name ?? '').($auction->venue_name && $auction->venue_address ? ', ' : '').($auction->venue_address ?? ''));
        if ($venue !== '') $rows[] = ['Venue', $venue];
        $rows[] = ['Format', ['in_room' => 'In-room auction', 'online' => 'Online auction', 'hybrid' => 'In-room and online auction'][$auction->bidding_mode] ?? null];
        $rows[] = ['Conducted by', $auction->isInternal() ? ($auction->auctioneerUser?->name.' — '.$property->agency?->name) : ($auction->auctioneer_company ?: $property->agency?->name)];
        if (! $auction->isInternal() && $auction->auctioneer_licence_no) $rows[] = ['Licence no.', $auction->auctioneer_licence_no];
        if ($auction->auctioneer_phone) $rows[] = ['Phone', $auction->auctioneer_phone];
        if ($auction->auctioneer_email) $rows[] = ['Email', $auction->auctioneer_email];
        if ($auction->registration_closes_at) $rows[] = ['Registration closes', $auction->registration_closes_at->format('d M Y, H:i')];
        if ($ai['guideEnabled'] && ($lot->guide_price_min || $lot->guide_price_max)) {
            $g = $money($lot->guide_price_min ?: $lot->guide_price_max);
            if ($lot->guide_price_min && $lot->guide_price_max && $lot->guide_price_max != $lot->guide_price_min) $g .= ' – '.$money($lot->guide_price_max);
            $rows[] = ['Guide price', $g];
        }
        if ($lot->opening_bid) $rows[] = ['Opening bid', $money($lot->opening_bid)];
        if ($lot->reserve_price === null) $rows[] = ['Reserve', 'To be confirmed by the auctioneer'];
        elseif ((float) $lot->reserve_price <= 0) $rows[] = ['Reserve', 'Offered without reserve'];
        else $rows[] = ['Reserve', 'Subject to a reserve price'.($ai['showReserve'] ? ': '.$money($lot->reserve_price) : '')];
        $rows = array_values(array_filter($rows, fn ($r) => $r[1] !== null && $r[1] !== ''));
    @endphp
    <section class="mt-10">
        <h2 class="text-navy text-2xl font-light">Auction details</h2>
        <dl class="mt-4 divide-y divide-slate-200 rounded-sm border border-slate-200 bg-slate-50">
            @foreach($rows as [$label, $value])
                <div class="flex justify-between gap-4 px-5 py-3.5 text-sm">
                    <dt class="text-neutral-600">{{ $label }}</dt>
                    <dd class="text-navy font-medium text-right">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
        @if($ai['viewings']->isNotEmpty())
            <h3 class="text-navy mt-6 text-lg font-light">Viewings</h3>
            <ul class="mt-2 divide-y divide-slate-200 rounded-sm border border-slate-200 bg-slate-50 text-sm">
                @foreach($ai['viewings'] as $v)
                    <li class="px-5 py-3">{{ $v->starts_at->format('l, d F Y, H:i') }} – {{ $v->ends_at->format('H:i') }}@if($v->is_by_appointment) <span class="text-neutral-500">(by appointment)</span>@endif</li>
                @endforeach
            </ul>
        @endif
        @if($ai['registerUrl'])
            <a href="{{ $ai['registerUrl'] }}" target="_blank" rel="noopener" class="mt-4 inline-block rounded-sm bg-navy px-5 py-2.5 text-sm font-semibold text-white">Register to bid</a>
        @endif
    </section>
@endif
