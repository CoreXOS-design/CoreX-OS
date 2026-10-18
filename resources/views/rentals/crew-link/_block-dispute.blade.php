{{--
    .ai/specs/rental-work-orders.md §17.10.6 — the DISPUTE banner: the tenant looked at the finished work and says it
    is not complete. Shows what they wrote and the photos they sent, then points at "Report fixed" at the bottom of the
    page. Never shows who said it, a price or an owner amount.

    Receives $block = CrewDisputeBlock::for($card, $ctx) — a flat array of plain values; [] when the job is not disputed.
--}}
@if(!empty($block))
    <div class="cj-card" style="border-color:#f1c0b8; background:#fff5f3;" data-dispute-banner>
        <h2 style="color:#b3361f;">The tenant says this is not complete</h2>
        <div class="cj-muted" style="margin-bottom:8px;">
            Check {{ $block['raised_on'] ?? '' }} &middot; round {{ $block['round_no'] ?? 1 }}. Please go back, put it right, then press “Report fixed” below.
        </div>
        <div style="white-space:pre-line; font-size:15px; padding:10px 12px; background:#fff; border:1px solid #f1c0b8; border-radius:10px;">{{ $block['note'] }}</div>
        @if(!empty($block['photos']))
            <div class="cj-photos">
                @foreach($block['photos'] as $p)
                    <a href="{{ $p['url'] }}" target="_blank" rel="noopener"><img src="{{ $p['url'] }}" alt="Photo from the tenant" loading="lazy"></a>
                @endforeach
            </div>
        @endif
    </div>
@endif
