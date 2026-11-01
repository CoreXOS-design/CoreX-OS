@extends('public.auctions._layout')
@section('title', 'Auctions')

@php
    $nowOn = $live->filter(fn ($a) => $a->publicPhase() === 'live')->values();
    $coming = $live->filter(fn ($a) => in_array($a->publicPhase(), ['upcoming', 'draft'], true))->values();
    // The banner is the AGENCY'S OWN picture (Preview page) or the designed colour background - never a property photo, which may be a poor one
    // or not exist when no auction is running.
    $heroImage = $heroUrl;
    $openCount = $nowOn->count();
    $lotTotal = (int) $nowOn->sum('public_lots_count');
    $comingCount = $coming->where('status', '!=', 'draft')->count();
@endphp

@push('styles')
        /* The home page (.ai/specs/auctions.md §21) - same look as the lot page, every colour the agency's. */
        .home-hero { position:relative; min-height:440px; overflow:hidden; display:flex; align-items:flex-end; }
        .home-hero img.ph { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; display:block; }
        .home-hero { --hero-fg:#fff; --hero-chip:rgba(255,255,255,.16); --hero-line:rgba(255,255,255,.3); }
        .home-hero.no-img { --hero-fg:var(--on-brand); --hero-chip:color-mix(in srgb, var(--on-brand) 14%, transparent); --hero-line:color-mix(in srgb, var(--on-brand) 38%, transparent); }
        .home-hero.has-img::after { content:''; position:absolute; inset:0; background:linear-gradient(180deg, rgba(8,20,36,.35) 0%, rgba(8,20,36,.62) 45%, rgba(8,20,36,.88) 100%); }
        .home-hero .in { position:relative; z-index:1; width:100%; max-width:1080px; margin:0 auto; padding:64px 16px 52px; box-sizing:border-box; color:var(--hero-fg); }
        .home-hero.has-img h1, .home-hero.has-img p.lead { text-shadow:0 2px 18px rgba(0,0,0,.35); }
        .home-hero h1 { color:var(--hero-fg); margin:16px 0 0; font-size:3.4rem; line-height:1.04; letter-spacing:-.03em; font-weight:800; max-width:760px; }
        .home-hero p.lead { margin:14px 0 0; max-width:640px; font-size:1.15rem; line-height:1.55; font-weight:500; opacity:.94; }
        .home-hero .cta { display:flex; flex-wrap:wrap; gap:12px; margin-top:26px; }
        .home-hero .cta .btn { padding:14px 26px; font-size:1.02rem; border-radius:12px; font-weight:800; }
        .home-hero .cta .btn.ghost { background:var(--hero-chip); color:var(--hero-fg) !important; border:2px solid var(--hero-line); }
        .home-hero .stats { margin-top:26px; display:flex; flex-wrap:wrap; gap:10px; font-size:.85rem; font-weight:700; }
        .home-hero .stats span { background:var(--hero-chip); border:1px solid var(--hero-line); border-radius:99px; padding:7px 14px; }
        main.home-main { max-width:1080px; padding-top:8px; }
        .sec-title { display:flex; align-items:baseline; justify-content:space-between; gap:12px; margin:40px 0 16px; }
        .sec-title h2 { margin:0; font-size:1.5rem; font-weight:800; letter-spacing:-.02em; }
        .sec-title small { color:var(--muted); font-weight:600; }
        .agrid { display:grid; grid-template-columns:repeat(auto-fill, minmax(300px, 1fr)); gap:22px; }
        .acard { display:flex; flex-direction:column; background:var(--card); border:1px solid var(--line); border-radius:22px; overflow:hidden; text-decoration:none; color:var(--ink); box-shadow:0 8px 24px rgba(12,28,48,.06); transition:transform .15s ease, box-shadow .15s ease; }
        .acard:hover { transform:translateY(-3px); box-shadow:0 16px 36px rgba(12,28,48,.14); }
        .acard .ph { position:relative; height:200px; overflow:hidden; }
        .acard .ph img { width:100%; height:100%; object-fit:cover; display:block; }
        .acard .ph .pill { position:absolute; left:14px; top:14px; box-shadow:0 2px 8px rgba(0,0,0,.18); }
        .acard .body { padding:18px 20px 20px; display:flex; flex-direction:column; gap:6px; flex:1; }
        .acard .t { font-size:1.2rem; font-weight:800; letter-spacing:-.01em; line-height:1.25; }
        .acard .d { font-size:.88rem; color:var(--muted); font-weight:600; }
        .acard .m { margin-top:auto; padding-top:12px; display:flex; justify-content:space-between; align-items:center; gap:10px; font-size:.82rem; color:var(--muted); font-weight:600; }
        .acard .m b { color:var(--brand); white-space:nowrap; font-weight:800; }
        .acard.is-past { opacity:.82; } .acard.is-past .ph img { filter:grayscale(.55); }
        .steps { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:18px; }
        .step { background:var(--card); border:1px solid var(--line); border-radius:20px; padding:22px; }
        .step .n { width:40px; height:40px; border-radius:12px; background:var(--brand); color:var(--on-brand); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:1.05rem; }
        .step h3 { margin:14px 0 4px; font-size:1.1rem; font-weight:800; } .step p { margin:0; color:var(--muted); font-size:.92rem; font-weight:500; }
        .how-text { background:var(--card); border:1px solid var(--line); border-radius:20px; padding:24px 26px; font-size:1rem; line-height:1.65; white-space:pre-line; }
        .empty { background:var(--card); border:1px dashed var(--line); border-radius:20px; padding:36px 24px; text-align:center; color:var(--muted); font-weight:600; }
        @media (max-width:640px) { .home-hero { min-height:380px; } .home-hero h1 { font-size:2.2rem; } .agrid { grid-template-columns:1fr; } }
@endpush

@section('mainclass', 'home-main')

@section('hero')
    <div class="home-hero brand-bg {{ $heroImage ? 'has-img' : 'no-img' }}" data-hero>
        @if($heroImage)<img class="ph" src="{{ $heroImage }}" alt="">@endif
        <div class="in">
            @if($nowOn->isNotEmpty())
                <span class="pill live"><i></i>{{ $nowOn->count() === 1 ? 'An auction is live now' : $nowOn->count() . ' auctions are live now' }}</span>
            @elseif($coming->isNotEmpty())
                <span class="pill"><i></i>Next auction coming up</span>
            @endif
            <h1>{{ $siteTitle }}</h1>
            @if($intro !== '')<p class="lead">{{ $intro }}</p>@endif
            <div class="cta">
                @if($buyer)
                    <a class="btn" href="{{ route('public.auctions.account', $agency->slug) }}">My account</a>
                @else
                    <a class="btn" href="{{ route('public.auctions.signup', $agency->slug) }}">Sign up to bid</a>
                    <a class="btn ghost" href="{{ route('public.auctions.login', $agency->slug) }}">Log in</a>
                @endif
                @if($sellerForm ?? false)<a class="btn ghost" href="#questionnaire" data-sell-button>Sell your property at auction</a>@endif
            </div>
            @if($openCount > 0 || $comingCount > 0)
                <div class="stats" data-stats>
                    @if($openCount > 0)<span>{{ $openCount }} {{ $openCount === 1 ? 'auction' : 'auctions' }} open now</span><span>{{ $lotTotal }} {{ $lotTotal === 1 ? 'lot' : 'lots' }} to bid on</span>@endif
                    @if($comingCount > 0)<span>{{ $comingCount }} coming up</span>@endif
                </div>
            @endif
        </div>
    </div>
@endsection

@section('content')
    @if($live->isEmpty())
        <div class="empty" style="margin-top:32px;" data-no-auctions>There are no auctions open right now. Please check back soon.</div>
    @endif

    @if($nowOn->isNotEmpty())
        <div class="sec-title"><h2>Live now</h2><small>Bidding is open</small></div>
        <div class="agrid" data-live-auctions>@foreach($nowOn as $a)@include('public.auctions._auction-card', ['a' => $a])@endforeach</div>
    @endif

    @if($coming->isNotEmpty())
        <div class="sec-title"><h2>Coming up</h2><small>Register early so you are approved in time</small></div>
        <div class="agrid" data-coming-auctions>@foreach($coming as $a)@include('public.auctions._auction-card', ['a' => $a])@endforeach</div>
    @endif

    <div class="sec-title"><h2>How bidding works</h2></div>
    @if($howItWorks !== '')
        <div class="how-text" data-how-it-works>{{ $howItWorks }}</div>
    @else
        <div class="steps" data-how-steps>
            <div class="step"><div class="n">1</div><h3>Create a free account</h3><p>All you need is your email address. We send you a code to confirm it.</p></div>
            <div class="step"><div class="n">2</div><h3>Register for the auction</h3><p>Upload the documents requested, then wait for approval. We email you your bidder number.</p></div>
            <div class="step"><div class="n">3</div><h3>Place your bids</h3><p>Once approved, bid online while bidding is open and watch the lots you like.</p></div>
        </div>
    @endif

    @if($showPast && $past->isNotEmpty())
        <div class="sec-title"><h2>Past auctions</h2></div>
        <div class="agrid">@foreach($past as $a)@include('public.auctions._auction-card', ['a' => $a])@endforeach</div>
    @endif

    @if($sellerForm ?? false)
        @include('public.auctions._seller-form')
    @endif
@endsection
