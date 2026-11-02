{{-- mic-work-tab.md 13.18 (rework): ONE line under the facts: "Jan wants · 2+ beds ✓ · ≤ R1.2m ✓ · Flat or Townhouse ✓ · Pets ?". Only what the wishlist states, no dashes; every item is coloured on its text by the
     state the match engine gave it (green ok / red miss / amber unknown); ellipsis when long; the label is in the buyer's teal so it reads as a different thing from the facts line above it.
     Input: $fit = the row's buyer_fit. Nothing is judged here. --}}
@php
    $items = \App\Services\Prospecting\MicWork\BuyerFitLabel::wantsItems($fit);
    $first = $fit['first_name'] ?? 'Buyer';
@endphp
@if($items)
    <div data-qa="fit-line" style="font-size:.875rem; line-height:1.3; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
        <span data-qa="fit-label" style="font-weight:700; color: color-mix(in srgb, var(--brand-icon) 60%, black);">{{ $first }} wants</span>@foreach($items as $it)<span style="color: var(--text-secondary);"> · </span><span data-qa="fit-item" data-state="{{ $it['state'] }}" title="{{ $it['title'] }}" style="font-weight:600; color: {{ $it['color'] }};">{{ $it['text'] }}</span>@endforeach
    </div>
@endif
