{{--
    "Prospecting for a buyer" banner (mic-work-tab.md 13.14). Two lines:
      1. target icon, "Prospecting for <buyer>" (links to the contact), the match count, and at the right Core Match / Edit criteria / Clear buyer.
      2. the wishlist MIC matched on as chips (same ds-badge style as the Core Match results header), then "Primary wishlist · saved … by …".
    Core Match and Edit criteria open the real CoreX screen in a pop-up (iframe, embed mode, same origin). Renders only what the controller gives ($buyerBanner,
    built by MicBuyerBanner); nothing is worked out here.

    Inputs: $buyerBanner, $listings (paginator, for the count).
--}}
@php
    $bb = $buyerBanner ?? null;
    $total = $listings->total();
    $btn = 'corex-btn-outline text-xs no-underline inline-flex items-center flex-shrink-0';
@endphp
@if($bb)
<div x-data="micBuyerPop()" @keydown.escape.window="close()" @message.window="onMessage($event)" data-qa="buyer-banner"
     style="padding: 8px 12px; margin-bottom: 8px; border-radius: 6px; background: color-mix(in srgb, var(--brand-icon) 10%, var(--surface)); border: 1px solid color-mix(in srgb, var(--brand-icon) 30%, var(--border));">
    <div style="display: flex; align-items: center; gap: 8px;" data-qa="buyer-banner-line1">
        <span aria-hidden="true" style="font-size: 1rem;">🎯</span>
        <div style="flex: 1 1 auto; min-width: 0; display: flex; align-items: baseline; gap: 8px; white-space: nowrap; overflow: hidden;">
            <span style="font-size: .8125rem; font-weight: 700; color: var(--text-primary); flex-shrink: 0;">Prospecting for <a href="{{ $bb['buyer']['url'] }}" data-qa="buyer-banner-name" style="color: inherit; text-decoration: underline; text-decoration-color: var(--border);">{{ $bb['buyer']['name'] }}</a></span>
            @if(!empty($buyerUpdating))
            @include('corex.market-intelligence._buyer-updating')
            @else
            <span data-qa="buyer-banner-count" style="font-size: .6875rem; color: var(--text-secondary); overflow: hidden; text-overflow: ellipsis;">{{ number_format($total) }} propert{{ $total === 1 ? 'y' : 'ies' }} matching — strongest match first (Core Matches score)</span>
            @endif
            @include('corex.market-intelligence._buyer-hidden-link')
        </div>
        <div style="display: flex; align-items: center; gap: 6px; flex-shrink: 0;">
            @if($bb['results_url'])
                <button type="button" class="{{ $btn }}" data-qa="buyer-banner-core-match" @click="open('{{ $bb['results_url'] }}', 'Core Match · {{ addslashes($bb['buyer']['name']) }}', false, $el)">Core Match</button>
                <button type="button" class="{{ $btn }}" data-qa="buyer-banner-edit" @click="open('{{ $bb['edit_url'] }}', 'Edit criteria · {{ addslashes($bb['buyer']['name']) }}', true, $el)">Edit criteria</button>
            @endif
            <a href="{{ route('market-intelligence.work', request()->except(['buyer_id', 'page'])) }}" class="{{ $btn }}" data-qa="buyer-banner-clear">Clear buyer ×</a>
        </div>
    </div>

    <div style="display: flex; align-items: center; flex-wrap: wrap; gap: 4px 6px; margin: 6px 0 0 24px;" data-qa="buyer-banner-line2">
        @forelse($bb['chips'] as [$label, $tone, $full])
            <span class="ds-badge ds-badge-pill ds-badge-{{ $tone }}" @if($full) title="{{ $full }}" @endif>{{ $label }}</span>
        @empty
            <span style="font-size: .75rem; font-style: italic; color: var(--text-muted);">This buyer has no active wishlist to match on.</span>
        @endforelse
        @if($bb['wishlist'])
            <span data-qa="buyer-banner-meta" style="font-size: .6875rem; color: var(--text-muted);">
                {{ $bb['wishlist']['primary'] ? 'Primary wishlist' : 'Wishlist' }}@if($bb['wishlist']['saved']) · saved {{ $bb['wishlist']['saved'] }}@endif @if($bb['wishlist']['by'])by {{ $bb['wishlist']['by'] }}@endif
                @if($bb['wishlist_count'] > 1)
                    · <a href="{{ $bb['wishlists_url'] }}" data-qa="buyer-banner-wishlists" title="MIC scores every active wishlist and keeps the best score for each advert" style="color: var(--brand-icon);">{{ $bb['wishlist_count'] }} wishlists</a> (best score of all counts)
                @endif
            </span>
        @endif
        @if(!empty($bb['nudge']))
            @if($bb['edit_url'])
                <button type="button" data-qa="buyer-banner-nudge" @click="open('{{ $bb['edit_url'] }}', 'Edit criteria · {{ addslashes($bb['buyer']['name']) }}', true, $el)"
                        style="margin-left: auto; padding: 0 8px; font-size: .6875rem; line-height: 18px; cursor: pointer; border-radius: 10px; color: var(--text-primary); background: color-mix(in srgb, #f59e0b 18%, transparent); border: 1px solid #f59e0b;">Wishlist says only price and type — add suburbs or beds to narrow this list</button>
            @else
                <span data-qa="buyer-banner-nudge" style="margin-left: auto; font-size: .6875rem; color: var(--text-primary);">Wishlist says only price and type — add suburbs or beds to narrow this list</span>
            @endif
        @endif
    </div>

        <div x-show="isOpen" x-cloak class="fixed inset-0 z-50 flex items-center justify-center p-4" style="background: rgba(0,0,0,0.5);" role="dialog" aria-modal="true" :aria-label="label" data-qa="buyer-pop">
            <div class="flex flex-col w-full rounded-md overflow-hidden" style="max-width: 72rem; height: 90vh; background: var(--surface); border: 1px solid var(--border);">
                <div class="flex items-center justify-between px-4 py-2" style="border-bottom: 1px solid var(--border);">
                    <h2 class="text-sm font-bold" style="color: var(--text-primary);" x-text="label"></h2>
                    <button type="button" class="corex-btn-outline corex-btn-xs" x-ref="closeBtn" @click="close()" aria-label="Close and go back to Market Intelligence">Close</button>
                </div>
                <iframe x-ref="frame" class="flex-1 w-full" style="border: 0; background: var(--bg);" title="Core Match"></iframe>
            </div>
        </div>
</div>
@endif
