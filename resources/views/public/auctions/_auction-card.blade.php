@php
    $phase = $a->publicPhase();
    $lotCount = (int) ($a->public_lots_count ?? 0);
    $starts = $a->starts_at?->format('j M Y, H:i');
    $ends = $a->ends_at && $a->format === 'timed' ? $a->ends_at->format('j M Y, H:i') : null;
@endphp
<a href="{{ route('public.auctions.auction', [$agency->slug, $a->id]) }}" class="acard {{ $phase === 'finished' ? 'is-past' : '' }}" data-auction-card>
    <div class="ph brand-bg">
        @if($a->card_image ?? null)<img src="{{ $a->card_image }}" alt="" loading="lazy">@endif
        <span class="pill {{ $phase === 'live' ? 'live' : '' }}" data-auction-phase="{{ $phase }}"><i></i>{{ $phase === 'live' ? 'Live now' : $a->publicPhaseLabel() }}</span>
    </div>
    <div class="body">
        <div class="t">{{ $a->title }}</div>
        <div class="d">{{ $starts }}@if($ends) → {{ $ends }}@endif</div>
        <div class="m">
            <span>{{ $a->format === 'timed' ? 'Online bidding' : 'Live auction' }}@if($a->venue_name) · {{ $a->venue_name }}@endif · {{ $lotCount }} {{ $lotCount === 1 ? 'lot' : 'lots' }}</span>
            <b>View lots &rarr;</b>
        </div>
    </div>
</a>
