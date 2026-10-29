@extends('public.auctions._layout')
@section('title', 'Lot ' . $lot->lot_number . ' — ' . $lot->displayTitle())
@section('content')
    @php
        $p = $lot->property;
    @endphp
    <p class="small"><a href="{{ route('public.auctions.auction', [$agency->slug, $auction->id]) }}">&larr; {{ $auction->title }}</a></p>

    @if(count($photos))
    <div class="gallery">
        <img class="main" src="{{ $photos[0] }}" alt="">
        @foreach(array_slice($photos, 1, 2) as $ph)<img class="side" src="{{ $ph }}" alt="">@endforeach
    </div>
    @endif

    <div class="cols" style="margin-top:18px;">
        <div>
            <span class="badge">Lot {{ $lot->lot_number }}</span>
            @if($lot->status === 'sold')<span class="badge ok">Sold</span>@elseif($lot->status === 'passed_in')<span class="badge warn">Passed in</span>@elseif($lot->status === 'sold_subject_to_confirmation')<span class="badge warn">Sold subject to confirmation</span>@endif
            <h1>{{ $lot->displayTitle() }}</h1>
            <p class="muted">{{ trim(($p?->suburb ?? '') . ($p?->city ? ', ' . $p->city : ''), ', ') }}</p>
            @if($p && ($p->beds || $p->baths || $p->garages))
            <p class="small">@if($p->beds){{ $p->beds }} bed @endif @if($p->baths)· {{ $p->baths }} bath @endif @if($p->garages)· {{ $p->garages }} garage @endif</p>
            @endif
            <div style="white-space:pre-line;">{{ $lot->description ?: ($p?->description ?? '') }}</div>

            <h2>Documents</h2>
            @php $hasDocs = $documents->isNotEmpty() || $packDocuments->isNotEmpty(); @endphp
            @if(! $hasDocs)<p class="muted small">No documents have been published for this lot yet.</p>@else
            <ul class="docs">
                @foreach($documents as $d)<li><a href="{{ route('public.auctions.auction.document', [$agency->slug, $auction->id, $d->id]) }}">{{ $d->name }}</a> <span class="muted small">· auction</span></li>@endforeach
                @foreach($packDocuments as $d)<li><a href="{{ route('public.auctions.lot.document', [$agency->slug, $auction->id, $lot->id, $d->id]) }}">{{ $d->original_name }}</a></li>@endforeach
            </ul>
            @endif
            @if($hiddenCount > 0)
                <p class="small muted">{{ $hiddenCount }} more {{ $hiddenCount === 1 ? 'document is' : 'documents are' }} available once you <a href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">log in</a> or <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">sign up</a> (it is free).</p>
            @endif
        </div>

        <aside>
            <div class="panel" id="bidpanel">
                <div class="muted small" id="price-label">{{ $state['current_bid'] !== null ? 'Current bid' : 'Opening bid' }}</div>
                <div style="font-size:1.6rem;font-weight:800;" id="price-value">{{ $money($state['current_bid'] ?? $lot->opening_bid) ?: '—' }}</div>
                @if($lot->guide_price_min !== null || $lot->guide_price_max !== null)
                    <div class="small muted">Guide {{ $money($lot->guide_price_min) }}@if($lot->guide_price_max !== null) – {{ $money($lot->guide_price_max) }}@endif</div>
                @endif
                <div class="small" id="bid-count" @if(!isset($state['bid_count'])) style="display:none" @endif>{{ $state['bid_count'] ?? 0 }} {{ ($state['bid_count'] ?? 0) === 1 ? 'bid' : 'bids' }}</div>
                <div class="small" id="reserve-note" style="font-weight:600;{{ ($state['reserve'] ?? null) ? '' : 'display:none' }}">{{ ($state['reserve'] ?? null) === 'met' ? 'Reserve met' : 'Reserve not met' }}</div>

                <p class="small" style="margin:.8rem 0;" id="time-note">
                    @if($state['phase'] === 'ended')
                        Bidding has ended.
                    @elseif($state['phase'] === 'upcoming')
                        <strong>Bidding starts soon</strong><br>Opens {{ $lot->windowOpensAt()?->format('j M Y, H:i') }}
                    @else
                        <strong>Bidding is open</strong><br>Closes {{ $lot->windowClosesAt()?->format('j M Y, H:i') }}
                    @endif
                </p>

                @if($state['phase'] !== 'ended')
                    @if($auction->format !== 'timed')
                        <p class="small muted" style="margin-bottom:0;">This is a live auction. Bids are taken in the room{{ $auction->venue_name ? ' at ' . $auction->venue_name : '' }}.</p>
                        @if(! $buyer)
                            <a class="btn" href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Login to register</a>
                        @elseif(! $registration && $registrationOpen)
                            <a class="btn" href="{{ route('public.auctions.register', [$agency->slug, $auction->id]) }}">Register to bid</a>
                        @endif
                    @elseif(! $buyer)
                        <a class="btn" href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Login to bid</a>
                        <p class="small muted" style="margin-bottom:0;">New here? <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Create a free account</a>.</p>
                    @elseif(! $registration)
                        @if($registrationOpen)
                            <a class="btn" href="{{ route('public.auctions.register', [$agency->slug, $auction->id]) }}">Register to bid</a>
                            <p class="small muted" style="margin-bottom:0;">You must be approved for this auction before you can bid.</p>
                        @else
                            <p class="small muted" style="margin:0;">Registration for this auction has closed.</p>
                        @endif
                    @elseif($registration->status === 'pending')
                        <p class="small" style="margin:0;"><strong>Your registration is waiting for approval.</strong> We will email you.</p>
                    @elseif($registration->status === 'declined')
                        <p class="small" style="margin:0;"><strong>Your registration was not approved.</strong>@if($registration->declined_reason) {{ $registration->declined_reason }}@endif</p>
                        @if($registrationOpen)<p><a class="btn ghost" href="{{ route('public.auctions.register', [$agency->slug, $auction->id]) }}">Update and register again</a></p>@endif
                    @elseif($registration->status === 'approved')
                        <div id="bidbox">
                            <p class="small" style="margin:0 0 8px;">You are bidder <strong>#{{ $registration->bidder_number }}</strong>.</p>
                            <div id="you-note" class="small" style="font-weight:700;display:none;"></div>
                            <form id="bid-form" class="stack" style="margin-top:8px;">
                                <label for="bid-amount">Your bid (R)</label>
                                <input id="bid-amount" type="text" inputmode="numeric" autocomplete="off">
                                <p class="small muted" style="margin:4px 0 0;" id="min-note"></p>
                                <p style="margin-top:10px;"><button type="submit" class="btn" id="bid-btn">Place bid</button></p>
                            </form>
                            <details style="margin-top:6px;">
                                <summary class="small">Bid automatically up to a maximum</summary>
                                <form id="auto-form" class="stack">
                                    <label for="auto-max">Your maximum (R)</label>
                                    <input id="auto-max" type="text" inputmode="numeric" autocomplete="off">
                                    <p class="small muted" style="margin:4px 0 0;" id="auto-note"></p>
                                    <p style="margin-top:10px;"><button type="submit" class="btn ghost">Set auto-bid</button> <button type="button" class="btn ghost" id="auto-cancel" style="display:none;">Cancel auto-bid</button></p>
                                </form>
                            </details>
                        </div>
                    @endif
                @endif

                @if($buyer)
                    <p style="margin:12px 0 0;"><button type="button" class="btn ghost" id="watch-btn" style="padding:6px 12px;font-size:.85rem;">{{ $watching ? '★ Watching' : '☆ Watch this lot' }}</button></p>
                @endif
                @if(($feeNotice ?? '') !== '')<p class="small muted" style="margin:12px 0 0;" data-fee-notice>{{ $feeNotice }}</p>@endif
                <div id="bid-msg" class="small" style="margin-top:10px;"></div>
            </div>
        </aside>
    </div>

    <div style="margin-top:28px;max-width:560px;">
        @include('public.auctions._enquiry-form')
    </div>
<script>
(function () {
    var feedUrl = @json(route('api.v1.auctions.public.feed', $lot->id));
    var urls = { bid: @json(route('api.v1.auctions.public.bid', $lot->id)), auto: @json(route('api.v1.auctions.public.auto-bid', $lot->id)), cancelAuto: @json(route('api.v1.auctions.public.auto-bid.cancel', $lot->id)), watch: @json(route('api.v1.auctions.public.watch', $lot->id)) };
    var csrf = @json(csrf_token());
    var state = @json($state);
    var offset = new Date(state.server_now).getTime() - Date.now();
    var $ = function (id) { return document.getElementById(id); };
    var money = function (v) { return v == null ? '—' : 'R ' + Math.round(v).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
    var msg = function (t, bad) { var e = $('bid-msg'); e.textContent = t || ''; e.style.color = bad ? '#b91c1c' : '#166534'; };

    function left() { return state.closes_at ? Math.max(0, Math.floor((new Date(state.closes_at).getTime() - (Date.now() + offset)) / 1000)) : 0; }
    function fmtLeft(sec) { var d = Math.floor(sec / 86400), h = Math.floor(sec % 86400 / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
        return (d ? d + 'd ' : '') + (d || h ? h + 'h ' : '') + m + 'm ' + s + 's'; }

    function render() {
        $('price-label').textContent = state.current_bid != null ? 'Current bid' : 'Opening bid';
        $('price-value').textContent = money(state.current_bid != null ? state.current_bid : state.opening_bid);
        if (state.bid_count !== undefined) { $('bid-count').style.display = ''; $('bid-count').textContent = state.bid_count + (state.bid_count === 1 ? ' bid' : ' bids'); }
        var r = $('reserve-note'); r.style.display = state.reserve ? '' : 'none'; r.textContent = state.reserve === 'met' ? 'Reserve met' : 'Reserve not met';
        var t = $('time-note');
        if (state.phase === 'ended') t.innerHTML = 'Bidding has ended.';
        else if (state.phase === 'upcoming') t.innerHTML = '<strong>Bidding starts soon</strong>';
        else t.innerHTML = '<strong>Bidding is open</strong><br>Closes in ' + fmtLeft(left());
        var y = $('you-note');
        if (y) { y.style.display = state.you && state.you !== 'none' ? '' : 'none'; y.textContent = state.you === 'winning' ? 'You are the highest bidder.' : (state.you === 'outbid' ? 'You have been outbid.' : ''); y.style.color = state.you === 'winning' ? '#166534' : '#b91c1c'; }
        var mn = $('min-note'); if (mn) mn.textContent = state.min_next != null ? 'Minimum bid ' + money(state.min_next) : '';
        var an = $('auto-note'); if (an) an.textContent = state.your_auto_bid ? 'Your auto-bid maximum is ' + money(state.your_auto_bid) + '.' : '';
        var ac = $('auto-cancel'); if (ac) ac.style.display = state.your_auto_bid ? '' : 'none';
        if (state.phase === 'ended' && $('bidbox')) $('bidbox').style.display = 'none';
        var w = $('watch-btn'); if (w) w.textContent = state.watching ? '★ Watching' : '☆ Watch this lot';
    }

    function call(method, url, body) {
        return fetch(url, { method: method, credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: body ? JSON.stringify(body) : undefined })
            .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); });
    }
    function apply(res, okText) {
        if (res.ok) { state = res.j; offset = new Date(state.server_now).getTime() - Date.now(); render(); msg(okText, false); }
        else { var m = res.j && (res.j.message || (res.j.errors && Object.values(res.j.errors)[0][0])) || 'Something went wrong.'; msg(m, true); }
    }
    function num(v) { return parseFloat(String(v).replace(/[^0-9.]/g, '')); }

    var bf = $('bid-form');
    if (bf) bf.addEventListener('submit', function (e) { e.preventDefault(); var v = num($('bid-amount').value); if (!v) return msg('Enter an amount.', true);
        $('bid-btn').disabled = true; call('POST', urls.bid, { amount: v }).then(function (res) { $('bid-btn').disabled = false; apply(res, 'Your bid was placed.'); if (res.ok) $('bid-amount').value = ''; }); });
    var af = $('auto-form');
    if (af) af.addEventListener('submit', function (e) { e.preventDefault(); var v = num($('auto-max').value); if (!v) return msg('Enter your maximum.', true);
        call('POST', urls.auto, { max: v }).then(function (res) { apply(res, 'Auto-bid set.'); if (res.ok) $('auto-max').value = ''; }); });
    var ac = $('auto-cancel'); if (ac) ac.addEventListener('click', function () { call('DELETE', urls.cancelAuto).then(function (res) { apply(res, 'Auto-bid cancelled.'); }); });
    var wb = $('watch-btn'); if (wb) wb.addEventListener('click', function () { call('POST', urls.watch).then(function (res) { if (res.ok) { state.watching = res.j.watching; render(); } }); });

    function poll() { if (state.phase === 'ended') return;
        fetch(feedUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j) { var prevYou = state.you; state = j; offset = new Date(state.server_now).getTime() - Date.now(); render(); if (prevYou === 'winning' && j.you === 'outbid') msg('You have been outbid.', true); } }).catch(function () {}); }
    render(); setInterval(function () { if (state.phase === 'open') $('time-note').innerHTML = '<strong>Bidding is open</strong><br>Closes in ' + fmtLeft(left()); }, 1000); setInterval(poll, 3000);
})();
</script>
@endsection
