@extends('layouts.corex')

@section('corex-content')
@php
    $fieldStyle = 'border:1px solid var(--border);background:var(--surface-2);color:var(--text-primary);';
    $canReserve = auth()->user()->hasPermission('auctions.reserve.view');
    $money = fn ($v) => $v === null ? '—' : 'R ' . number_format((float) $v, 0, '.', ' ');
    $isLive = $auction->format === 'live';
@endphp
<div class="corex-auctions w-full flex flex-col gap-4">
    <div class="rounded-md px-6 py-5 corex-page-banner flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-base font-bold" style="color:var(--text-primary);">Sale Room — {{ $auction->title }}</h1>
            <p class="text-xs" style="color:var(--text-muted);">{{ $auction->reference }} · {{ \App\Models\Auction::FORMATS[$auction->format] ?? $auction->format }} · {{ $auction->statusLabel() }} · {{ $approved }} approved {{ $approved === 1 ? 'bidder' : 'bidders' }}
                @unless($isLive) · <strong>Monitor only: a timed auction runs on its own clock.</strong> @endunless</p>
        </div>
        <div class="flex gap-2">
            @permission('auctions.bidders.view')<a href="{{ route('corex.auctions.bidders.index', ['auction_id' => $auction->id]) }}" class="corex-btn-outline text-xs px-3 py-2">Bidders</a>@endpermission
            <a href="{{ route('corex.auctions.edit', $auction->id) }}" class="corex-btn-outline text-xs px-3 py-2">← The auction</a>
        </div>
    </div>

    @if(session('status'))<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#10b981 12%,transparent);color:#10b981;">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,#ef4444 12%,transparent);color:#ef4444;">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif

    @if($lots->isEmpty())
        <div class="text-center py-16 text-sm" style="color:var(--text-muted);">This auction has no lots yet.</div>
    @else
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-4">
        {{-- lots --}}
        <aside class="rounded-md overflow-hidden" style="background:var(--surface);border:1px solid var(--border);" data-room-lots>
            @foreach($lots as $l)
            <a href="{{ route('corex.auctions.room.show', [$auction->id, 'lot' => $l->id]) }}" class="block px-4 py-2.5 text-sm"
               style="border-top:1px solid var(--border);{{ $lot && $lot->id === $l->id ? 'background:var(--surface-2);' : '' }}color:var(--text-primary);text-decoration:none;">
                <strong>Lot {{ $l->lot_number }}</strong> — {{ \Illuminate\Support\Str::limit($l->displayTitle(), 28) }}
                <div class="text-xs" style="color:{{ $l->status === 'open' ? '#10b981' : 'var(--text-muted)' }};">{{ $l->statusLabel() }}@if($l->hammer_price !== null) · {{ $money($l->hammer_price) }}@endif</div>
            </a>
            @endforeach
        </aside>

        {{-- the lot on the block --}}
        <section class="lg:col-span-3 space-y-4">
            @if($lot)
            <div class="rounded-md p-5 space-y-3" style="background:var(--surface);border:1px solid var(--border);" data-room-lot="{{ $lot->id }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-bold" style="color:var(--text-primary);">Lot {{ $lot->lot_number }} — {{ $lot->displayTitle() }}</h2>
                        <p class="text-xs" style="color:var(--text-muted);">{{ $lot->property?->buildDisplayAddress() }}</p>
                    </div>
                    <span class="px-3 py-1 rounded-full text-xs font-semibold" style="background:var(--surface-2);">{{ $lot->statusLabel() }}</span>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3 text-sm">
                    <div><div class="text-xs" style="color:var(--text-muted);">Opening bid</div><strong>{{ $money($lot->opening_bid) }}</strong></div>
                    <div><div class="text-xs" style="color:var(--text-muted);">Current bid</div><strong id="room-current">{{ $high ? $money($high->amount) : '—' }}</strong>@if($highReg)<div class="text-xs" id="room-leader">Bidder #{{ $highReg->bidder_number }} {{ $highReg->displayName() }}</div>@endif</div>
                    <div><div class="text-xs" style="color:var(--text-muted);">Next minimum</div><strong>{{ $minNext !== null ? $money($minNext) : '—' }}</strong></div>
                    @if($canReserve)
                    <div><div class="text-xs" style="color:var(--text-muted);">Reserve (staff only)</div><strong>{{ $money($lot->reserve_price) }}</strong>
                        @if($lot->reserve_price !== null && $high)<div class="text-xs" style="color:{{ (float) $high->amount >= (float) $lot->reserve_price ? '#10b981' : '#b45309' }};">{{ (float) $high->amount >= (float) $lot->reserve_price ? 'Reserve met' : 'Reserve not met' }}</div>@endif</div>
                    @endif
                </div>

                @if($canOperate && $lot->status === 'published')
                    <form method="POST" action="{{ route('corex.auctions.room.open', [$auction->id, $lot->id]) }}">@csrf<button class="corex-btn-primary">Open Lot {{ $lot->lot_number }} for bids</button></form>
                @endif

                @if($canOperate && $lot->status === 'open')
                <form method="POST" action="{{ route('corex.auctions.room.bid', [$auction->id, $lot->id]) }}" class="flex flex-wrap items-end gap-3" data-floor-bid>
                    @csrf
                    <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Bidder number</label>
                        <input name="bidder_number" type="number" min="1" required autofocus class="px-3 py-2 text-sm rounded-md w-32" style="{{ $fieldStyle }}"></div>
                    <div><label class="block text-xs font-semibold mb-1" style="color:var(--text-secondary);">Bid (R)</label>
                        <input name="amount" type="number" min="1" step="1" required value="{{ $minNext !== null ? (int) $minNext : '' }}" class="px-3 py-2 text-sm rounded-md w-44" style="{{ $fieldStyle }}"></div>
                    <button class="corex-btn-primary">Record bid</button>
                </form>
                @endif

                @if($canHammer && $lot->status === 'open')
                <div class="flex flex-wrap items-center gap-3 pt-2" style="border-top:1px solid var(--border);">
                    @if($high)
                    <form method="POST" action="{{ route('corex.auctions.room.hammer', [$auction->id, $lot->id]) }}" onsubmit="return confirm('Knock Lot {{ $lot->lot_number }} down at {{ $money($high->amount) }}?');">@csrf<button class="corex-btn-primary" style="background:#b91c1c;">Knock down at {{ $money($high->amount) }}</button></form>
                    @endif
                    <form method="POST" action="{{ route('corex.auctions.room.pass-in', [$auction->id, $lot->id]) }}" onsubmit="return confirm('Pass this lot in?');" class="flex items-center gap-2">@csrf
                        <input name="note" placeholder="Note (optional)" class="px-2 py-2 text-xs rounded-md" style="{{ $fieldStyle }}"><button class="corex-btn-outline">Pass in</button></form>
                </div>
                @endif

                @if(in_array($lot->status, ['sold', 'sold_subject_to_confirmation', 'passed_in'], true))
                    <p class="text-sm"><strong>{{ $lot->statusLabel() }}</strong>@if($lot->hammer_price !== null) at {{ $money($lot->hammer_price) }}@endif
                        <a href="{{ route('corex.auctions.lots.edit', $lot->id) }}" class="underline text-xs ml-2">Open the lot</a></p>
                @endif
            </div>

            <div class="rounded-md p-5" style="background:var(--surface);border:1px solid var(--border);" data-room-bids>
                <h3 class="text-sm font-bold mb-2" style="color:var(--text-primary);">Bids ({{ $bids->where('status', 'valid')->count() }})</h3>
                @forelse($bids as $b)
                <div class="flex items-center gap-3 text-sm py-1.5" style="border-top:1px solid var(--border);{{ $b->status === 'retracted' ? 'opacity:.55;' : '' }}">
                    <span class="w-24 text-xs" style="color:var(--text-muted);">{{ $b->placed_at->format('H:i:s') }}</span>
                    <span class="flex-1">#{{ $b->registration?->bidder_number ?? '—' }} {{ $b->registration?->displayName() }}</span>
                    <strong>{{ $money($b->amount) }}</strong>
                    <span class="text-xs w-20">{{ \App\Models\AuctionBid::SOURCES[$b->source] ?? $b->source }}{{ $b->status === 'retracted' ? ' · retracted' : '' }}</span>
                    @if($b->status === 'valid' && ! $lot->isConcluded())@permission('auctions.bids.retract')
                    <form method="POST" action="{{ route('corex.auctions.bids.retract', $b->id) }}" class="flex items-center gap-1" onsubmit="return confirm('Retract this bid?');">@csrf
                        <input name="reason" placeholder="Reason" required class="px-2 py-1 text-xs rounded-md w-24" style="{{ $fieldStyle }}"><button class="text-xs underline" style="color:#ef4444;">Retract</button></form>
                    @endpermission @endif
                </div>
                @empty
                <p class="text-xs" style="color:var(--text-muted);">No bids on this lot yet.</p>
                @endforelse
            </div>
            @endif
        </section>
    </div>
    @endif
</div>

@if($lot)
<script>
    // Keep the room live: when a bid (from the web or another operator) changes the lot, reload to show it. Never while typing.
    (function () {
        var feed = @json(route('api.v1.auctions.lots.feed', $lot->id));
        var seen = { bids: @json($bids->where('status', 'valid')->count()), status: @json($lot->status), top: @json($high ? (float) $high->amount : null) };
        setInterval(function () {
            if (document.activeElement && ['INPUT', 'TEXTAREA', 'SELECT'].indexOf(document.activeElement.tagName) !== -1 && document.activeElement.value) return;
            fetch(feed, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
                if (!j) return;
                var n = j.bids.filter(function (b) { return b.status === 'valid'; }).length;
                if (n !== seen.bids || j.state.lot_status !== seen.status || j.state.current_bid !== seen.top) location.reload();
            }).catch(function () {});
        }, 3000);
    })();
</script>
@endif
@endsection
