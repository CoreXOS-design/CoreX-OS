{{-- AT-363 — per-wishlist match grid. Fed either from BuyerDetailController::show()
     (the default-expanded wishlist, pre-rendered) or ::wishlistMatches() (lazy-loaded
     on accordion expand). $matches is the same {id,address,price,suburb,match_score,
     days_on_market} shape as BuyerIntelligenceService::getMatchedProperties(). --}}
@if($matches->isEmpty())
    <div class="text-xs py-4 text-center" style="color: var(--text-muted);">No matching properties yet for this wishlist.</div>
@else
    <div class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-2.5">
        @foreach($matches->take(6) as $mp)
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
        @endforeach
    </div>
    @if(isset($buyer, $match))
        <div class="text-right mt-2">
            <a href="{{ route('corex.contacts.matches.results', [$buyer, $match]) }}"
               class="text-[11px] no-underline hover:underline" style="color: var(--brand-icon, #0ea5e9);">
                Open full match results ({{ number_format($matches->count()) }}) →
            </a>
        </div>
    @endif
@endif
