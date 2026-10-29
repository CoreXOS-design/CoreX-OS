@extends('public.auctions._layout')
@section('title', 'Lot ' . $lot->lot_number . ' — ' . $lot->displayTitle())
@section('content')
    @php
        $p = $lot->property;
        $now = now();
        $opens = $lot->windowOpensAt();
        $closes = $lot->windowClosesAt();
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
            <div class="panel">
                @if($lot->opening_bid !== null)
                    <div class="muted small">Opening bid</div>
                    <div style="font-size:1.6rem;font-weight:800;">{{ $money($lot->opening_bid) }}</div>
                @endif
                @if($lot->guide_price_min !== null || $lot->guide_price_max !== null)
                    <div class="small muted">Guide {{ $money($lot->guide_price_min) }}@if($lot->guide_price_max !== null) – {{ $money($lot->guide_price_max) }}@endif</div>
                @endif

                <p class="small" style="margin:.8rem 0;">
                    @if(in_array($lot->status, ['sold', 'sold_subject_to_confirmation', 'passed_in']))
                        Bidding has ended.
                    @elseif($opens && $now->lt($opens))
                        <strong>Bidding starts soon</strong><br>Opens {{ $opens->format('j M Y, H:i') }}
                    @elseif($closes)
                        <strong>Bidding is open</strong><br>Closes {{ $closes->format('j M Y, H:i') }}
                    @endif
                </p>

                @if(! in_array($lot->status, ['sold', 'sold_subject_to_confirmation', 'passed_in']))
                    @if($buyer)
                        <button class="btn" disabled>Register to bid — opening soon</button>
                        <p class="small muted" style="margin-bottom:0;">You are signed in as {{ $buyer->email }}. Bidder registration for this auction opens soon.</p>
                    @else
                        <a class="btn" href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Login to bid</a>
                        <p class="small muted" style="margin-bottom:0;">New here? <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Create a free account</a>.</p>
                    @endif
                @endif
            </div>
        </aside>
    </div>

    <div style="margin-top:28px;max-width:560px;">
        @include('public.auctions._enquiry-form')
    </div>
@endsection
