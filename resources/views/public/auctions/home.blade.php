@extends('public.auctions._layout')
@section('title', 'Auctions')
@section('content')
    @if($heroUrl)
    <div class="hero" style="background-image:url('{{ $heroUrl }}');" data-hero>
        <h1>{{ $siteTitle }}</h1>
        @if($intro !== '')<p>{{ $intro }}</p>@endif
    </div>
    @else
    <h1>{{ $siteTitle }}</h1>
    @if($intro !== '')<p class="muted" style="max-width:720px;">{{ $intro }}</p>@endif
    @endif

    @if(! $buyer)
    <div class="panel" style="margin:16px 0;">
        <strong>Want to bid?</strong> <span class="muted">Create a free account to see all auction documents, register for an auction and bid.</span>
        <a class="btn" style="margin-left:8px;" href="{{ route('public.auctions.signup', $agency->slug) }}">Sign up</a>
        <a class="btn ghost" href="{{ route('public.auctions.login', $agency->slug) }}">Log in</a>
    </div>
    @endif

    <h2>Upcoming &amp; open auctions</h2>
    @if($live->isEmpty())
        <p class="muted">There are no auctions open right now. Please check back soon.</p>
    @else
        <div class="grid">
        @foreach($live as $a)
            @include('public.auctions._auction-card', ['a' => $a])
        @endforeach
        </div>
    @endif

    @if($howItWorks !== '')
    <h2>How bidding works</h2>
    <div class="panel" style="white-space:pre-line;" data-how-it-works>{{ $howItWorks }}</div>
    @endif

    @if($showPast && $past->isNotEmpty())
    <h2>Past auctions</h2>
    <div class="grid">
        @foreach($past as $a)
            @include('public.auctions._auction-card', ['a' => $a])
        @endforeach
    </div>
    @endif
@endsection
