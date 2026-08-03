{{-- AT-363 — one page of a wishlist's match cards. Fed either from
     BuyerDetailController::show() (the default-expanded wishlist's first
     page, pre-rendered directly into the static grid wrapper in
     detail.blade.php) or ::wishlistMatches() (every page after that,
     fetched as JSON and appended into that same grid client-side — "Load
     more" never replaces the grid, only adds to it). $matches is the same
     {id,address,price,suburb,match_score,days_on_market} shape as
     BuyerIntelligenceService::getMatchedProperties().

     No wrapping grid element here on purpose — the grid + its scroll
     container are STATIC markup in detail.blade.php so "Load more" can
     insertAdjacentHTML into it without ever replacing what's already there. --}}
@forelse($matches as $mp)
    @php
        // Spec §3.13: never use red for a neutral score. Use green/amber/brand.
        $score = (int) ($mp['match_score'] ?? 0);
        $scoreBadgeClass = $score >= 90 ? 'ds-badge-success' : ($score >= 75 ? 'ds-badge-warning' : 'ds-badge-info');
    @endphp
    <div class="rounded-md p-3" style="background: var(--surface-2); border: 1px solid var(--border);">
        <div class="flex items-center justify-between mb-1 gap-2">
            <span class="text-xs font-semibold truncate" style="color: var(--text-primary);">{{ $mp['address'] }}</span>
            <span class="ds-badge {{ $scoreBadgeClass }}">{{ number_format($score) }}%</span>
        </div>
        <div class="text-[10px]" style="color: var(--text-muted);">{{ $mp['suburb'] }} · R {{ number_format($mp['price'] ?? 0) }} · {{ isset($mp['days_on_market']) ? number_format((int) $mp['days_on_market']) . 'd' : '—' }}</div>
    </div>
@empty
    <div class="col-span-full text-xs py-4 text-center" style="color: var(--text-muted);">No matching properties yet for this wishlist.</div>
@endforelse
