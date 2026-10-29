<a href="{{ route('public.auctions.auction', [$agency->slug, $a->id]) }}" class="card" style="text-decoration:none;color:inherit;">
    @if($a->cover_image_path)<img class="cover" src="{{ asset('storage/' . $a->cover_image_path) }}" alt="">@endif
    <div class="pad">
        <span class="badge {{ in_array($a->status, ['open']) ? 'ok' : (in_array($a->status, ['closed', 'completed']) ? '' : 'warn') }}">{{ $a->status === 'published' ? 'Upcoming' : ucfirst($a->status) }}</span>
        <div style="font-weight:700;margin-top:6px;">{{ $a->title }}</div>
        <div class="muted small">{{ $a->starts_at?->format('j M Y, H:i') }}@if($a->ends_at && $a->format === 'timed') – {{ $a->ends_at->format('j M Y, H:i') }}@endif</div>
        <div class="muted small">{{ $a->format === 'timed' ? 'Online bidding' : 'Live auction' }}@if($a->venue_name) · {{ $a->venue_name }}@endif · {{ $a->public_lots_count ?? 0 }} {{ ($a->public_lots_count ?? 0) === 1 ? 'lot' : 'lots' }}</div>
    </div>
</a>
