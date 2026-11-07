@extends('public.auctions._layout')
@section('title', $auction->title)
@section('content')
    <p class="small"><a href="{{ route('public.auctions.home', $agency->slug) }}">&larr; All auctions</a></p>
    @if($auction->cover_image_path)<img src="{{ asset('storage/' . $auction->cover_image_path) }}" alt="" style="width:100%;max-height:320px;object-fit:cover;border-radius:12px;margin-bottom:14px;">@endif
    <h1>{{ $auction->title }}</h1>
    <p class="muted">{{ $auction->reference }} · {{ $auction->format === 'timed' ? 'Online timed auction' : 'Live auction' }}</p>

    <div class="cols">
        <div>
            @if($auction->description)<div style="white-space:pre-line;">{{ $auction->description }}</div>@endif

            <h2>Lots</h2>
            @if($lots->isEmpty())
                <p class="muted">The lots for this auction will appear here soon.</p>
            @else
            <div class="grid">
                @foreach($lots as $lot)
                    @php $thumb = ($photo = \App\Models\Property::publicImageUrl($lot->property?->thumbFor(($lot->property->gallery_images_json[0] ?? null) ?? ($lot->property->images_json[0] ?? null)))) ?: null; @endphp
                    <a class="card" href="{{ route('public.auctions.lot', [$agency->slug, $auction->id, $lot->id]) }}" style="text-decoration:none;color:inherit;">
                        @if($thumb)<img class="cover" src="{{ $thumb }}" alt="">@endif
                        <div class="pad">
                            <span class="badge">Lot {{ $lot->lot_number }}</span>
                            @if($lot->status === 'sold')<span class="badge ok">Sold</span>@elseif($lot->status === 'passed_in')<span class="badge warn">Passed in</span>@endif
                            <div style="font-weight:700;margin-top:6px;">{{ $lot->displayTitle() }}</div>
                            <div class="muted small">{{ trim(($lot->property?->suburb ?? '') . ($lot->property?->city ? ', ' . $lot->property->city : ''), ', ') }}</div>
                            @if($lot->opening_bid !== null)<div class="small" style="margin-top:4px;">Opening bid <strong>R {{ number_format((float) $lot->opening_bid, 0, '.', ' ') }}</strong></div>@endif
                        </div>
                    </a>
                @endforeach
            </div>
            @endif
        </div>

        <aside>
            <div class="panel">
                <strong>When &amp; where</strong>
                <p class="small" style="margin:.4rem 0 0;">
                    Starts {{ $auction->starts_at?->format('l, j F Y \a\t H:i') }}<br>
                    @if($auction->ends_at && $auction->format === 'timed')Closes {{ $auction->ends_at->format('l, j F Y \a\t H:i') }}<br>@endif
                    @if($auction->venue_name){{ $auction->venue_name }}@if($auction->venue_address), {{ $auction->venue_address }}@endif<br>@elseif($auction->format === 'timed')Online<br>@endif
                    @if($auction->registration_closes_at)Registration closes {{ $auction->registration_closes_at->format('j M Y, H:i') }}<br>@endif
                </p>
                @if($auction->stream_url)<p class="small"><a href="{{ $auction->stream_url }}" target="_blank" rel="noopener">Watch the live stream</a></p>@endif
            </div>

            @if(($feeNotice ?? '') !== '')
            <div class="panel" style="margin-top:16px;" data-fee-notice><strong>Fees</strong><p class="small" style="margin:.4rem 0 0;">{{ $feeNotice }}</p></div>
            @endif

            <div class="panel" style="margin-top:16px;">
                <strong>Documents</strong>
                @if($documents->isEmpty())
                    <p class="muted small">No documents yet.</p>
                @else
                <ul class="docs">
                    @foreach($documents as $d)
                    <li><a href="{{ route('public.auctions.auction.document', [$agency->slug, $auction->id, $d->id]) }}">{{ $d->name }}</a></li>
                    @endforeach
                </ul>
                @endif
                @if($hiddenDocuments > 0)
                    <p class="small muted">{{ $hiddenDocuments }} more {{ $hiddenDocuments === 1 ? 'document is' : 'documents are' }} available once you <a href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">log in</a> or <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">sign up</a>.</p>
                @endif
            </div>
        </aside>
    </div>

    <div style="margin-top:28px;max-width:560px;">
        @include('public.auctions._enquiry-form', ['lot' => null])
    </div>
@endsection
