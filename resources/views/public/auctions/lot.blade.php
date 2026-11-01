@extends('public.auctions._layout')
@section('title', 'Lot ' . $lot->lot_number . ' — ' . $lot->displayTitle())
@section('mainclass', 'lot-main')

@push('styles')
        /* The lot page (.ai/specs/auctions.md §20): a full-width hero, then two columns with the bid card overlapping the hero. Every colour is the agency's. */
        .lot-hero { position:relative; height:540px; overflow:hidden; background:linear-gradient(135deg, var(--brand), color-mix(in srgb, var(--brand) 55%, var(--button))); }
        .lot-hero img.ph { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
        .lot-hero::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(8,20,36,0) 35%, rgba(8,20,36,.78) 100%); }
        .lot-hero .in { position:absolute; left:0; right:0; bottom:0; z-index:1; max-width:1080px; margin:0 auto; padding:0 16px 56px; color:#fff; }
        .lot-hero h1 { color:#fff; margin:0; font-size:3.4rem; line-height:1.04; letter-spacing:-.03em; font-weight:800; max-width:720px; }
        .lot-hero .addr { margin-top:10px; font-size:1.1rem; font-weight:500; opacity:.92; }
        .lot-hero .meta { display:flex; align-items:center; flex-wrap:wrap; gap:12px; margin-bottom:14px; font-size:.8rem; font-weight:700; letter-spacing:.14em; text-transform:uppercase; }
        main.lot-main { max-width:1080px; padding-top:0; }
        .lot-cols { display:flex; flex-wrap:wrap; gap:44px; align-items:flex-start; }
        .lot-left { flex:999 1 520px; min-width:0; padding-top:44px; }
        .lot-aside { flex:1 1 360px; max-width:420px; position:relative; z-index:2; padding-top:24px; }
        @media (min-width:900px) { .lot-aside { margin-top:-160px; padding-top:0; } }
        .eyebrow { font-size:.75rem; font-weight:800; letter-spacing:.16em; text-transform:uppercase; color:var(--brand); }
        .lot-about { margin:10px 0 0; font-size:1.2rem; line-height:1.55; font-weight:600; letter-spacing:-.01em; white-space:pre-line; }
        .facts { display:grid; grid-template-columns:repeat(auto-fit, minmax(110px, 1fr)); margin-top:30px; border-top:1px solid var(--line); border-bottom:1px solid var(--line); }
        .facts > div { padding:18px 0 18px 22px; border-left:1px solid var(--line); } .facts > div:first-child { padding-left:0; border-left:0; }
        .facts b { display:block; font-size:2.3rem; font-weight:800; letter-spacing:-.02em; color:var(--brand); line-height:1.1; } .facts span { font-size:.8rem; font-weight:600; color:var(--muted); }
        .lot-card { background:var(--card); border:1px solid var(--line); border-radius:24px; padding:26px 28px; }
        .lot-card h2 { margin:0; font-size:1.25rem; font-weight:800; letter-spacing:-.01em; }
        .act-row { display:flex; align-items:center; gap:14px; padding:13px 0; border-top:1px solid #edf0f5; }
        .act-row .no { width:38px; height:38px; border-radius:50%; background:#e6ecf5; font-weight:800; display:flex; align-items:center; justify-content:center; font-size:.9rem; flex:0 0 auto; }
        .act-row.top .no { background:var(--button); color:var(--on-button); }
        .act-row .amt { font-weight:800; font-size:1.05rem; } .act-row .sub { font-size:.8rem; color:var(--muted); font-weight:500; }
        .act-row .tag { margin-left:auto; font-size:.72rem; font-weight:800; padding:5px 11px; border-radius:99px; background:#e8f6ee; color:#166534; white-space:nowrap; }
        .act-row .tag.you { background:#e6ecf5; color:#344054; }
        .two { display:flex; flex-wrap:wrap; gap:32px; margin-top:34px; } .two > div { flex:1 1 250px; }
        .big-guide { margin-top:8px; font-size:1.6rem; font-weight:800; letter-spacing:-.02em; }
        .photos { display:grid; grid-template-columns:repeat(auto-fill, minmax(150px, 1fr)); gap:10px; margin-top:12px; } .photos img { width:100%; height:110px; object-fit:cover; border-radius:12px; display:block; }
        .bid-card { background:var(--card); border-radius:24px; padding:28px; box-shadow:0 24px 60px rgba(12,28,48,.22); border:1px solid #e6eaf1; }
        .bid-card .lbl { font-size:.75rem; font-weight:800; letter-spacing:.16em; text-transform:uppercase; color:var(--muted); }
        #price-value { font-size:3.2rem !important; font-weight:800 !important; line-height:1.05; letter-spacing:-.03em; color:var(--brand); margin-top:4px; }
        #reserve-note { display:inline-block; margin-top:10px; font-size:.8rem; font-weight:700; color:#8a4b08; background:#fdf0dc; padding:6px 12px; border-radius:99px; }
        #time-note { margin:18px 0 0 !important; padding:14px 16px; border-radius:14px; background:#f1f4f9; display:flex; justify-content:space-between; align-items:center; gap:10px; font-size:.85rem; }
        #time-note .tn-l { color:var(--muted); font-weight:600; } #time-note .tn-v { font-weight:800; font-size:1.35rem; letter-spacing:-.01em; color:var(--ink); } #time-note .tn-s { font-weight:700; color:var(--ink); }
        #bid-amount { font-size:1.9rem !important; font-weight:800; letter-spacing:-.02em; border:2px solid var(--brand) !important; border-radius:14px !important; padding:12px 14px !important; }
        #quick-bids .qb { cursor:pointer; padding:10px 14px; min-height:44px; border-radius:12px; border:0; background:#e6ecf5; color:var(--ink); font:inherit; font-weight:800; font-size:.88rem; }
        #quick-bids .qb.min { background:transparent; color:var(--brand); text-decoration:underline; }
        #bid-btn { width:100%; min-height:56px; border-radius:14px; font-size:1.15rem; font-weight:800; }
        #bid-btn[disabled] { opacity:.4; }
        .lot-main .btn { border-radius:12px; font-weight:800; }
        #bidbox { margin-top:18px; }
        #bidpanel > a.btn { display:block; text-align:center; margin-top:16px; padding:14px 18px; }
        .lot-main .btn.ghost { background:transparent; color:var(--brand) !important; border:2px solid var(--brand); }
        @media (max-width:640px) { .lot-hero { height:380px; } .lot-hero h1 { font-size:2.2rem; } .facts > div { padding-left:14px; } }
@endpush

@section('hero')
    @php
        $p = $lot->property;
        $phase = $state['phase'] ?? 'open';
        $closedLabel = $lot->status === 'sold' ? 'Sold' : ($lot->status === 'passed_in' ? 'Passed in' : ($lot->status === 'sold_subject_to_confirmation' ? 'Sold subject to confirmation' : 'Bidding ended'));
    @endphp
    <div class="lot-hero" data-lot-hero>
        @if(count($photos))<img class="ph" src="{{ $photos[0] }}" alt="">@endif
        <div class="in">
            <div class="meta">
                <span class="pill {{ $phase === 'open' ? 'live' : '' }}" id="phase-pill"><i></i><span id="phase-text">{{ $phase === 'open' ? 'Live · bidding open' : ($phase === 'upcoming' ? 'Upcoming' : $closedLabel) }}</span></span>
                <span>Lot {{ $lot->lot_number }} · {{ $auction->format === 'timed' ? 'Online auction' : 'Live auction' }}</span>
            </div>
            <h1>{{ $lot->displayTitle() }}</h1>
            @php
                $suburb = trim((string) ($p?->suburb ?? '')); $city = trim((string) ($p?->city ?? ''));
                $addr = $city !== '' && strcasecmp($city, $suburb) !== 0 ? trim($suburb . ', ' . $city, ', ') : $suburb;   // "Pennington, Pennington" reads as a mistake
            @endphp
            @if($addr !== '')<div class="addr">{{ $addr }}</div>@endif
        </div>
    </div>
@endsection

@section('content')
    @php
        $beds = (int) ($p?->beds ?? 0); $baths = (int) ($p?->baths ?? 0); $garages = (int) ($p?->garages ?? 0); $size = (float) ($p?->size_m2 ?? 0);
        $about = trim((string) ($lot->description ?: ($p?->description ?? '')));
        $recent = $state['recent_bids'] ?? null;
    @endphp
    <p class="small" style="margin:18px 0 0;"><a href="{{ route('public.auctions.auction', [$agency->slug, $auction->id]) }}">&larr; {{ $auction->title }}</a></p>

    <div class="lot-cols">
        <div class="lot-left">
            @if($about !== '')
                <div class="eyebrow">About this property</div>
                <p class="lot-about">{{ $about }}</p>
            @endif

            @if($beds || $baths || $garages || $size > 0)
            <div class="facts" data-facts>
                @if($beds)<div><b>{{ $beds }}</b><span>{{ $beds === 1 ? 'Bedroom' : 'Bedrooms' }}</span></div>@endif
                @if($baths)<div><b>{{ $baths }}</b><span>{{ $baths === 1 ? 'Bathroom' : 'Bathrooms' }}</span></div>@endif
                @if($garages)<div><b>{{ $garages }}</b><span>{{ $garages === 1 ? 'Garage' : 'Garages' }}</span></div>@endif
                @if($size > 0)<div><b>{{ number_format($size) }}<small style="font-size:.5em"> m²</small></b><span>Floor size</span></div>@endif
            </div>
            @endif

            @if($recent !== null)
            <div class="lot-card" id="activity" style="margin-top:34px;" data-activity>
                <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;">
                    <h2>Bidding activity</h2>
                    <span class="small muted" id="activity-count" style="font-weight:700;">{{ isset($state['bid_count']) ? $state['bid_count'] . ' ' . ($state['bid_count'] === 1 ? 'bid' : 'bids') . ' so far' : '' }}</span>
                </div>
                <div id="activity-list" style="margin-top:10px;">
                    @forelse($recent as $r)
                        <div class="act-row {{ $r['highest'] ? 'top' : '' }}">
                            <span class="no">{{ $r['who'] ?? '•' }}</span>
                            <div><div class="amt">{{ $money($r['amount']) }}</div><div class="sub">{{ $r['who'] ? 'Bidder #' . $r['who'] : 'A bidder' }}</div></div>
                            @if($r['mine'])<span class="tag you">You</span>@elseif($r['highest'])<span class="tag">Highest</span>@endif
                        </div>
                    @empty
                        <p class="muted small" style="margin:8px 0 0;">No bids yet. The first bid will show here.</p>
                    @endforelse
                </div>
            </div>
            @endif

            @if($lot->guide_price_min !== null || $lot->guide_price_max !== null || $lot->opening_bid !== null)
            <div class="two">
                @if($lot->guide_price_min !== null || $lot->guide_price_max !== null)
                <div>
                    <div class="eyebrow">Guide price</div>
                    <div class="big-guide">{{ $money($lot->guide_price_min) }}@if($lot->guide_price_max !== null) – {{ $money($lot->guide_price_max) }}@endif</div>
                    @if($lot->reserve_price !== null)<div class="small muted" style="margin-top:4px;">The reserve price is never shown to bidders.</div>@endif
                </div>
                @endif
                <div>
                    <div class="eyebrow">Documents</div>
                    @php $hasDocs = $documents->isNotEmpty() || $packDocuments->isNotEmpty(); @endphp
                    @if(! $hasDocs)<p class="muted small" style="margin:10px 0 0;">No documents have been published for this lot yet.</p>@else
                    <ul class="docs" style="margin-top:6px;">
                        @foreach($documents as $d)<li><a href="{{ route('public.auctions.auction.document', [$agency->slug, $auction->id, $d->id]) }}">{{ $d->name }}</a> <span class="muted small">· auction</span></li>@endforeach
                        @foreach($packDocuments as $d)<li><a href="{{ route('public.auctions.lot.document', [$agency->slug, $auction->id, $lot->id, $d->id]) }}">{{ $d->original_name }}</a></li>@endforeach
                    </ul>
                    @endif
                    @if($hiddenCount > 0)
                        <p class="small muted">{{ $hiddenCount }} more {{ $hiddenCount === 1 ? 'document is' : 'documents are' }} available once you <a href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">log in</a> or <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">sign up</a> (it is free).</p>
                    @endif
                </div>
            </div>
            @else
            <div style="margin-top:34px;">
                <div class="eyebrow">Documents</div>
                @php $hasDocs = $documents->isNotEmpty() || $packDocuments->isNotEmpty(); @endphp
                @if(! $hasDocs)<p class="muted small" style="margin:10px 0 0;">No documents have been published for this lot yet.</p>@else
                <ul class="docs" style="margin-top:6px;">
                    @foreach($documents as $d)<li><a href="{{ route('public.auctions.auction.document', [$agency->slug, $auction->id, $d->id]) }}">{{ $d->name }}</a> <span class="muted small">· auction</span></li>@endforeach
                    @foreach($packDocuments as $d)<li><a href="{{ route('public.auctions.lot.document', [$agency->slug, $auction->id, $lot->id, $d->id]) }}">{{ $d->original_name }}</a></li>@endforeach
                </ul>
                @endif
                @if($hiddenCount > 0)
                    <p class="small muted">{{ $hiddenCount }} more {{ $hiddenCount === 1 ? 'document is' : 'documents are' }} available once you <a href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">log in</a> or <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">sign up</a> (it is free).</p>
                @endif
            </div>
            @endif

            @if(count($photos) > 1)
            <div style="margin-top:34px;">
                <div class="eyebrow">More photos</div>
                <div class="photos">@foreach(array_slice($photos, 1, 11) as $ph)<a href="{{ $ph }}" target="_blank" rel="noopener"><img src="{{ $ph }}" alt=""></a>@endforeach</div>
            </div>
            @endif

            <div style="margin-top:38px;">
                @include('public.auctions._enquiry-form')
            </div>
        </div>

        <aside class="lot-aside">
            <div class="bid-card" id="bidpanel">
                <div style="display:flex;justify-content:space-between;align-items:baseline;gap:8px;">
                    <div class="lbl" id="price-label">{{ $state['current_bid'] !== null ? 'Current bid' : 'Opening bid' }}</div>
                    <div class="small muted" id="bid-count" @if(!isset($state['bid_count'])) style="display:none" @endif>{{ $state['bid_count'] ?? 0 }} {{ ($state['bid_count'] ?? 0) === 1 ? 'bid' : 'bids' }}</div>
                </div>
                <div id="price-value">{{ $money($state['current_bid'] ?? $lot->opening_bid) ?: '—' }}</div>
                <div id="reserve-note" style="{{ ($state['reserve'] ?? null) ? '' : 'display:none' }}">{{ ($state['reserve'] ?? null) === 'met' ? 'Reserve met' : 'Reserve not met' }}</div>

                <p class="small" id="time-note">
                    @if($state['phase'] === 'ended')
                        <span class="tn-s">Bidding has ended.</span>
                    @elseif($state['phase'] === 'upcoming')
                        <span class="tn-l">Bidding starts soon</span><span class="tn-s">Opens {{ $lot->windowOpensAt()?->format('j M, H:i') }}</span>
                    @else
                        <span class="tn-l">Bidding is open</span>@if($lot->windowClosesAt())<span class="tn-s">Closes {{ $lot->windowClosesAt()->format('j M, H:i') }}</span>@endif
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
                                <input id="bid-amount" type="text" inputmode="numeric" autocomplete="off" aria-describedby="min-note">
                                <div id="quick-bids" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:8px;" aria-label="Add to your bid"></div>
                                <p class="small muted" style="margin:6px 0 0;" id="min-note"></p>
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

    // "Closes in" countdown (timed lots), "Bidding is open" (a live auction has no clock), "starts soon" or "ended"
    function timeHtml() {
        if (state.phase === 'ended') return '<span class="tn-s">Bidding has ended.</span>';
        if (state.phase === 'upcoming') return '<span class="tn-l">Bidding starts soon</span>' + (state.opens_at ? '<span class="tn-s">Opens ' + new Date(state.opens_at).toLocaleString('en-ZA', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) + '</span>' : '');
        return state.closes_at ? '<span class="tn-l">Closes in</span><span class="tn-v">' + fmtLeft(left()) + '</span>' : '<span class="tn-l">Bidding is open</span>';
    }
    function ago(iso) {
        if (!iso) return '';
        var s = Math.max(0, Math.floor(((Date.now() + offset) - new Date(iso).getTime()) / 1000));
        if (s < 45) return 'just now'; if (s < 3600) return Math.round(s / 60) + ' min ago'; if (s < 86400) return Math.round(s / 3600) + ' h ago';
        return new Date(iso).toLocaleDateString('en-ZA', { day: 'numeric', month: 'short' });
    }
    // the activity list: built with textContent so nothing from the feed is ever treated as markup
    function renderActivity() {
        var list = $('activity-list'); if (!list || !state.recent_bids) return;
        var cnt = $('activity-count'); if (cnt && state.bid_count !== undefined) cnt.textContent = state.bid_count + (state.bid_count === 1 ? ' bid' : ' bids') + ' so far';
        list.textContent = '';
        if (!state.recent_bids.length) { var e = document.createElement('p'); e.className = 'muted small'; e.style.margin = '8px 0 0'; e.textContent = 'No bids yet. The first bid will show here.'; list.appendChild(e); return; }
        state.recent_bids.forEach(function (r) {
            var row = document.createElement('div'); row.className = 'act-row' + (r.highest ? ' top' : '');
            var no = document.createElement('span'); no.className = 'no'; no.textContent = r.who != null ? String(r.who) : '•';
            var mid = document.createElement('div'), amt = document.createElement('div'), sub = document.createElement('div');
            amt.className = 'amt'; amt.textContent = money(r.amount); sub.className = 'sub'; sub.textContent = (r.who != null ? 'Bidder #' + r.who : 'A bidder') + ' · ' + ago(r.at);
            mid.appendChild(amt); mid.appendChild(sub); row.appendChild(no); row.appendChild(mid);
            if (r.mine || r.highest) { var tag = document.createElement('span'); tag.className = 'tag' + (r.mine ? ' you' : ''); tag.textContent = r.mine ? 'You' : 'Highest'; row.appendChild(tag); }
            list.appendChild(row);
        });
    }

    function render() {
        $('price-label').textContent = state.current_bid != null ? 'Current bid' : 'Opening bid';
        $('price-value').textContent = money(state.current_bid != null ? state.current_bid : state.opening_bid);
        if (state.bid_count !== undefined) { $('bid-count').style.display = ''; $('bid-count').textContent = state.bid_count + (state.bid_count === 1 ? ' bid' : ' bids'); }
        var r = $('reserve-note'); r.style.display = state.reserve ? '' : 'none'; r.textContent = state.reserve === 'met' ? 'Reserve met' : 'Reserve not met';
        $('time-note').innerHTML = timeHtml();
        var pill = $('phase-pill'), pt = $('phase-text');
        if (pill && pt && state.phase !== 'ended') { pill.className = 'pill' + (state.phase === 'open' ? ' live' : ''); pt.textContent = state.phase === 'open' ? 'Live · bidding open' : 'Upcoming'; }
        renderActivity();
        var y = $('you-note');
        if (y) { y.style.display = state.you && state.you !== 'none' ? '' : 'none'; y.textContent = state.you === 'winning' ? 'You are the highest bidder.' : (state.you === 'outbid' ? 'You have been outbid.' : ''); y.style.color = state.you === 'winning' ? '#166534' : '#b91c1c'; }
        var mn = $('min-note'); if (mn) { mn.textContent = state.min_next != null ? 'Your bid must be at least ' + money(state.min_next) + '.' : ''; }
        renderBidBox();
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

    // ── the bid box: starts at the lowest allowed bid, "+ step" buttons add to it, anything below the minimum is refused ──
    var amountEl = $('bid-amount'), touched = false;
    var group = function (n) { return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
    function setAmount(n) { if (amountEl) amountEl.value = group(n); }
    function checkAmount() {
        var btn = $('bid-btn'), mn = $('min-note'); if (!amountEl || !btn || state.min_next == null) return;
        var v = num(amountEl.value), low = !v || v < state.min_next - 0.005;
        btn.disabled = low;
        if (mn) { mn.style.color = low && amountEl.value !== '' ? '#b91c1c' : ''; mn.textContent = low && amountEl.value !== '' ? 'That is too low. Your bid must be at least ' + money(state.min_next) + '.' : 'Your bid must be at least ' + money(state.min_next) + '.'; }
    }
    function renderBidBox() {
        if (!amountEl || state.min_next == null) return;
        // until the bidder types or taps something, the box follows the lowest allowed bid as other people bid
        if (!touched || !num(amountEl.value)) { setAmount(state.min_next); touched = false; }
        var q = $('quick-bids'), inc = state.increment;
        if (q && inc && q.getAttribute('data-inc') !== String(inc)) {
            q.setAttribute('data-inc', String(inc)); q.innerHTML = '';
            [1, 2, 5, 10].forEach(function (m) { var b = document.createElement('button'); b.type = 'button'; b.className = 'qb';
                b.setAttribute('data-add', String(inc * m)); b.textContent = '+ ' + group(inc * m); q.appendChild(b); });
            var r = document.createElement('button'); r.type = 'button'; r.className = 'qb min'; r.setAttribute('data-reset', '1'); r.textContent = 'Minimum'; q.appendChild(r);
        }
        checkAmount();
    }
    if (amountEl) {
        amountEl.addEventListener('input', function () { touched = true; var d = amountEl.value.replace(/[^0-9]/g, ''); amountEl.value = d === '' ? '' : group(parseInt(d, 10)); checkAmount(); });
        var qb = $('quick-bids');
        if (qb) qb.addEventListener('click', function (e) { var b = e.target.closest ? e.target.closest('button') : null; if (!b) return; touched = true;
            if (b.hasAttribute('data-reset')) setAmount(state.min_next);
            else setAmount(Math.max(num(amountEl.value) || 0, state.min_next) + parseFloat(b.getAttribute('data-add')));
            checkAmount(); });
    }

    var bf = $('bid-form');
    if (bf) bf.addEventListener('submit', function (e) { e.preventDefault(); var v = num($('bid-amount').value); if (!v) return msg('Enter an amount.', true);
        if (state.min_next != null && v < state.min_next - 0.005) { checkAmount(); return msg('Your bid must be at least ' + money(state.min_next) + '.', true); }
        $('bid-btn').disabled = true; call('POST', urls.bid, { amount: v }).then(function (res) { $('bid-btn').disabled = false; apply(res, 'Your bid was placed.'); if (res.ok) { touched = false; renderBidBox(); } }); });
    var af = $('auto-form');
    if (af) af.addEventListener('submit', function (e) { e.preventDefault(); var v = num($('auto-max').value); if (!v) return msg('Enter your maximum.', true);
        call('POST', urls.auto, { max: v }).then(function (res) { apply(res, 'Auto-bid set.'); if (res.ok) $('auto-max').value = ''; }); });
    var ac = $('auto-cancel'); if (ac) ac.addEventListener('click', function () { call('DELETE', urls.cancelAuto).then(function (res) { apply(res, 'Auto-bid cancelled.'); }); });
    var wb = $('watch-btn'); if (wb) wb.addEventListener('click', function () { call('POST', urls.watch).then(function (res) { if (res.ok) { state.watching = res.j.watching; render(); } }); });

    function poll() { if (state.phase === 'ended') return;
        fetch(feedUrl, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) { if (j) { var prevYou = state.you; state = j; offset = new Date(state.server_now).getTime() - Date.now(); render(); if (prevYou === 'winning' && j.you === 'outbid') msg('You have been outbid.', true); } }).catch(function () {}); }
    render(); setInterval(function () { if (state.phase === 'open') $('time-note').innerHTML = timeHtml(); }, 1000); setInterval(renderActivity, 30000); setInterval(poll, 3000);
})();
</script>
@endsection
