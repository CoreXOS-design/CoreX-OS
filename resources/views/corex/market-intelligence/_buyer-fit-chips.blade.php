{{-- mic-work-tab.md 13.18: the buyer's must-haves and deal-breakers as small chips under the description: "Pool ✓ · Pet friendly ? · Sea view ✗ · Deal-breaker: none".
     Input: $fit = the row's buyer_fit. Renders nothing when the buyer has set neither. A deal-breaker is ok when the property does NOT have it, miss when it does. --}}
@php
    $B = \App\Services\Prospecting\MicWork\BuyerFitLabel::class;
    $must = $fit['must_haves'] ?? [];
    $breakers = $fit['deal_breakers'] ?? [];
    $pill = 'font-size:.6875rem; font-weight:700; line-height:1.4; padding:0 6px; border:1px solid currentColor; border-radius:8px; white-space:nowrap;';
@endphp
@if($must || $breakers)
    <div data-qa="fit-chips" style="display:flex; flex-wrap:wrap; align-items:center; gap:3px 6px; margin-top:4px;">
        @foreach($must as $m)
            <span data-qa="fit-must" title="Must-have: {{ $m['label'] }}" style="{{ $pill }} color: {{ $B::color($m['state'] ?? null) }};">{{ trim($m['label'] . ' ' . $B::mark($m['state'] ?? null)) }}</span>
        @endforeach
        @if($breakers)
            @foreach($breakers as $d)
                <span data-qa="fit-breaker" title="Deal-breaker: {{ $d['label'] }}" style="{{ $pill }} color: {{ $B::color($d['state'] ?? null) }};">{{ trim('Deal-breaker: ' . $d['label'] . ' ' . $B::mark($d['state'] ?? null)) }}</span>
            @endforeach
        @else
            <span data-qa="fit-breaker" style="{{ $pill }} color: var(--text-secondary);">Deal-breaker: none</span>
        @endif
    </div>
@endif
