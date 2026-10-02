@extends('layouts.corex')

@section('corex-content')
<div class="corex-auctions w-full h-full flex flex-col gap-4">
    <div class="-mx-4 lg:-mx-6 -mt-4 lg:-mt-6 px-6 py-3.5 flex-shrink-0 flex flex-wrap items-center justify-between gap-3" style="border-bottom:1px solid var(--border);">
        <div>
            <a href="{{ route('corex.auctions.show', $auction) }}" class="text-sm text-muted underline">&larr; {{ $auction->title }}</a>
            <h1 class="text-base font-bold leading-tight" style="color:var(--text-primary);">Sale Room</h1>
        </div>
        <div class="flex gap-1 text-xs">
            @foreach($lots as $l)
                <a href="{{ route('corex.auctions.room.show', [$auction, 'lot' => $l->id]) }}"
                   class="px-2 py-1 rounded border {{ $lot && $lot->id === $l->id ? 'bg-blue-600 text-white' : 'bg-gray-50' }}">
                    Lot {{ $l->lot_number }}
                </a>
            @endforeach
        </div>
    </div>

    @if($errors->any())<div class="rounded-md px-4 py-2 text-sm" style="background:color-mix(in srgb,var(--ds-crimson,#dc2626) 12%,transparent);color:var(--ds-crimson,#dc2626);">{{ $errors->first() }}</div>@endif

    @if(!$lot)
        <div class="text-center py-16 text-muted">No lot is ready for the floor — catalogue and publish a lot first.</div>
    @else
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 flex-1">
            {{-- Current lot, large --}}
            <div class="lg:col-span-2 rounded-md p-6 flex flex-col gap-4" style="background:var(--surface);border:1px solid var(--border);">
                <div>
                    <div class="text-xs text-muted">Lot {{ $lot->lot_number }} — {{ ucwords(str_replace('_', ' ', $lot->status)) }}</div>
                    <div class="text-2xl font-semibold">{{ $lot->property?->buildDisplayAddress() ?? ('Property #'.$lot->property_id) }}</div>
                </div>

                <div class="grid grid-cols-3 gap-4 text-center">
                    <div>
                        <div class="text-xs text-muted">
                            Current Bid
                            @if($currentHighBid && $currentHighBid->channel !== 'in_room')
                                <span id="room-online-badge" class="ml-1 px-1.5 py-0.5 rounded bg-blue-600 text-white text-[10px] font-semibold">ONLINE</span>
                            @else
                                <span id="room-online-badge" class="ml-1 px-1.5 py-0.5 rounded bg-blue-600 text-white text-[10px] font-semibold hidden">ONLINE</span>
                            @endif
                        </div>
                        <div id="room-current-bid" class="text-3xl font-bold">{{ $currentHighBid ? 'R '.number_format($currentHighBid->amount, 0) : '—' }}</div>
                        <div id="room-current-bid-detail" class="text-xs text-muted">
                            @if($currentHighBid)Paddle {{ $currentHighBid->bidder?->paddle_number }} — {{ $currentHighBid->bidder?->contact?->full_name }}@endif
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-muted">Next Increment</div>
                        <div id="room-next-increment" class="text-3xl font-bold">{{ $suggestedNextBid !== null ? 'R '.number_format($suggestedNextBid, 0) : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-muted">Bidders</div>
                        <div id="room-bidder-count" class="text-3xl font-bold">{{ $bidderCount }}</div>
                    </div>
                </div>
                @if($auction->bidding_mode !== 'in_room' && $lot && in_array($lot->status, ['open_for_bids', 'under_the_hammer']))
                <div id="room-online-notice" class="text-xs text-muted hidden">Live feed — updates every 5s. An online bid just landed; refresh the page before knocking down.</div>
                @endif

                @if($canSeeReserve)
                <div class="text-sm text-muted">Reserve: {{ $lot->reserve_price ? 'R '.number_format($lot->reserve_price, 0) : 'No reserve' }}</div>
                @endif

                {{-- Lot status controls --}}
                <div class="flex gap-2">
                    @if($lot->status === 'catalogued')
                    <form method="POST" action="{{ route('corex.auctions.room.open', [$auction, $lot]) }}">@csrf<button class="corex-btn-primary">Open for Bids</button></form>
                    @endif
                    @if($lot->status === 'open_for_bids')
                    <form method="POST" action="{{ route('corex.auctions.room.hammer.start', [$auction, $lot]) }}">@csrf<button class="corex-btn-primary">Start Hammer</button></form>
                    @endif
                </div>

                {{-- One-keystroke bid entry --}}
                @if(in_array($lot->status, ['open_for_bids', 'under_the_hammer']))
                <form method="POST" action="{{ route('corex.auctions.room.bid', [$auction, $lot]) }}" class="flex gap-2 items-end" autocomplete="off">
                    @csrf
                    <div>
                        <label class="prop-label">Paddle #</label>
                        <input type="text" name="paddle_number" autofocus class="prop-input text-lg" style="width:8rem">
                    </div>
                    <div>
                        <label class="prop-label">Amount (blank = next increment)</label>
                        <input type="number" name="amount" step="0.01" class="prop-input" placeholder="{{ $suggestedNextBid !== null ? 'R '.number_format($suggestedNextBid, 0) : 'Type a starting amount' }}">
                    </div>
                    <button type="submit" class="corex-btn-primary">Bid</button>
                </form>

                <div class="flex gap-2">
                    @if($canRetract && $currentHighBid)
                    <form method="POST" action="{{ route('corex.auctions.room.bid.retract', [$auction, $lot]) }}" onsubmit="return confirm('Retract the last bid?')" class="flex gap-2 items-end">
                        @csrf
                        <input type="text" name="reason" placeholder="Reason" class="prop-input text-xs">
                        <button type="submit" class="corex-btn-outline text-xs">Retract Last Bid</button>
                    </form>
                    @endif
                </div>
                @endif

                {{-- Fall of the hammer — deliberate, confirmed action (§11.1) --}}
                @if($lot->status === 'under_the_hammer')
                <div class="border-t pt-4 flex gap-2">
                    <form method="POST" action="{{ route('corex.auctions.room.hammer.knock-down', [$auction, $lot]) }}"
                          onsubmit="return confirm('Going once… going twice… SOLD to paddle {{ $currentHighBid->bidder->paddle_number ?? '?' }} for R {{ number_format($currentHighBid->amount ?? 0, 0) }}?\n\nThis cannot be undone from here.')">
                        @csrf
                        <button type="submit" class="corex-btn-primary" @if(!$currentHighBid) disabled @endif>Fall of the Hammer — SOLD</button>
                    </form>
                    <form method="POST" action="{{ route('corex.auctions.room.hammer.pass-in', [$auction, $lot]) }}" onsubmit="return confirm('No bid reached the reserve — pass this lot in?')" class="flex gap-2 items-end">
                        @csrf
                        <input type="text" name="reason" placeholder="Reason (optional)" class="prop-input text-xs">
                        <button type="submit" class="corex-btn-outline">Pass In</button>
                    </form>
                </div>
                @endif
            </div>

            {{-- Bid history --}}
            <div class="rounded-md p-4" style="background:var(--surface);border:1px solid var(--border);">
                <h2 class="font-medium mb-2 text-sm">Bid History</h2>
                <div id="room-bid-history" class="flex flex-col gap-1 text-sm max-h-96 overflow-y-auto">
                    @forelse($bidHistory as $bid)
                    <div class="flex justify-between border-b py-1 {{ $bid->isRetracted() ? 'opacity-40 line-through' : '' }}">
                        <span>#{{ $bid->bidder?->paddle_number }} ({{ $bid->channel }})</span>
                        <span>R {{ number_format($bid->amount, 0) }}</span>
                    </div>
                    @empty
                    <p class="text-muted">No bids yet.</p>
                    @endforelse
                </div>

                <h2 class="font-medium mt-4 mb-2 text-sm">Approved Bidders</h2>
                <div class="text-sm flex flex-col gap-1">
                    @foreach($approvedBidders as $ab)
                    <div>#{{ $ab->paddle_number }} — {{ $ab->contact?->full_name }}</div>
                    @endforeach
                </div>
            </div>
        </div>
    @endif
</div>

@if($auction->bidding_mode !== 'in_room' && $lot && in_array($lot->status, ['open_for_bids', 'under_the_hammer']))
<script>
// AT-432 Phase 4 (§11.4) — hybrid Sale Room: poll the catalogued bid feed
// so an online/proxy bid appears live without the clerk reloading the
// page. Plain setInterval + fetch — "no new realtime infrastructure"
// (§11.2). Never touches the bid-entry form or the fall-of-hammer
// controls; those still act on the server-rendered state on submit.
(function () {
    const feedUrl = @json(route('api.v1.auctions.lots.feed', $lot->id));
    const noticeEl = document.getElementById('room-online-notice');
    let lastBidId = {{ $currentHighBid?->id ?? 'null' }};

    function fmt(amount) {
        return 'R ' + Math.round(amount).toLocaleString('en-ZA');
    }

    async function poll() {
        let data;
        try {
            data = await window.CoreX.api.fetch(feedUrl);
        } catch (e) {
            return; // transient network hiccup — try again next tick
        }

        document.getElementById('room-current-bid').textContent = data.current_bid ? fmt(data.current_bid.amount) : '—';
        document.getElementById('room-current-bid-detail').textContent = data.current_bid ? ('Paddle ' + (data.current_bid.bidder_paddle_number ?? '?')) : '';
        document.getElementById('room-next-increment').textContent = data.suggested_next_bid !== null ? fmt(data.suggested_next_bid) : '—';
        document.getElementById('room-bidder-count').textContent = data.distinct_bidder_count;

        const badge = document.getElementById('room-online-badge');
        const isOnlineLeading = !!data.current_bid && data.current_bid.channel !== 'in_room';
        badge.classList.toggle('hidden', !isOnlineLeading);

        const newestId = (data.recent_bids && data.recent_bids[0]) ? data.recent_bids[0].id : null;
        if (newestId !== null && newestId !== lastBidId) {
            lastBidId = newestId;
            if (noticeEl && isOnlineLeading) {
                noticeEl.classList.remove('hidden');
            }
            const history = document.getElementById('room-bid-history');
            if (history) {
                history.innerHTML = data.recent_bids.map(function (b) {
                    return '<div class="flex justify-between border-b py-1">'
                        + '<span>#' + (b.bidder_paddle_number ?? '?') + ' (' + b.channel + ')</span>'
                        + '<span>' + fmt(b.amount) + '</span>'
                        + '</div>';
                }).join('') || '<p class="text-muted">No bids yet.</p>';
            }
        }
    }

    setInterval(poll, 5000);
})();
</script>
@endif
@endsection
