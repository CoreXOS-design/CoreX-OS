{{-- Who runs the sale + how to register + the legal documents. Shared by the auction and lot pages. --}}
<div class="rounded-xl bg-white border border-slate-200 p-4 space-y-3 text-sm">
    <div>
        <div class="text-xs uppercase tracking-wide text-slate-400">Auction</div>
        <div class="font-semibold">{{ $auction->title }}</div>
        <div class="text-slate-600">{{ $auction->starts_at?->format('l, d F Y \a\t H:i') }}</div>
        @if($auction->venue_name || $auction->venue_address)
        <div class="text-slate-600">{{ $auction->venue_name }}@if($auction->venue_name && $auction->venue_address), @endif{{ $auction->venue_address }}</div>
        @endif
        <div class="text-slate-500">{{ ['in_room' => 'In-room auction', 'online' => 'Online auction', 'hybrid' => 'In-room and online auction'][$auction->bidding_mode] ?? '' }}@if($auction->is_online_streamed && $auction->stream_url) · <a class="underline" href="{{ $auction->stream_url }}">Watch live</a>@endif</div>
    </div>

    <div>
        <div class="text-xs uppercase tracking-wide text-slate-400">Conducted by</div>
        @if($auction->isInternal())
            <div>{{ $auction->auctioneerUser?->name }} — {{ $agency->name }}</div>
        @else
            <div>{{ $auction->auctioneer_company ?: $agency->name }}</div>
            @if($auction->auctioneer_licence_no)<div class="text-slate-500">Licence no. {{ $auction->auctioneer_licence_no }}</div>@endif
        @endif
        @if($auction->auctioneer_phone)<div><a class="underline" href="tel:{{ $auction->auctioneer_phone }}">{{ $auction->auctioneer_phone }}</a></div>@endif
        @if($auction->auctioneer_email)<div><a class="underline" href="mailto:{{ $auction->auctioneer_email }}">{{ $auction->auctioneer_email }}</a></div>@endif
    </div>

    @if($auction->registration_closes_at)
    <div class="text-slate-600">Registration closes {{ $auction->registration_closes_at->format('d M Y, H:i') }}.</div>
    @endif
    @if($registerUrl)
    <a href="{{ $registerUrl }}" class="inline-block rounded bg-amber-500 hover:bg-amber-600 text-white font-semibold px-4 py-2">Register to bid</a>
    @endif

    @if($auction->rules_file_path || $auction->conditions_file_path)
    <div class="border-t border-slate-100 pt-3">
        <div class="text-xs uppercase tracking-wide text-slate-400 mb-1">Please read before the sale</div>
        @if($auction->rules_file_path)<div><a class="underline" href="{{ route('public.auctions.document', [$auction->id, 'rules']) }}">Rules of Auction (PDF)</a></div>@endif
        @if($auction->conditions_file_path)<div><a class="underline" href="{{ route('public.auctions.document', [$auction->id, 'conditions']) }}">Conditions of Sale (PDF)</a></div>@endif
    </div>
    @endif
</div>
