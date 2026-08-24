{{--
    One wishlist's results, rendered as a section on the contact's single shared
    page. When the contact has more than one wishlist, `$showHeader` wraps this
    in a collapsible <details> headed by the wishlist's name so each search
    reads as its own dropdown; with only one wishlist it renders exactly as the
    page always has (no header chrome, no behaviour change).
--}}
@php
    $m          = $group['match'];
    $properties = $group['properties'];
    $feedback   = $group['feedback'];
    $filters    = $group['filters'];
    $groupToken = $group['token'];
    $totalCount = $properties->count();

    $priceVals  = $properties->pluck('price')->filter(fn ($p) => (int) $p > 0)->map(fn ($p) => (int) $p)->values();
    $priceFloor = $priceVals->min();
    $priceCeil  = $priceVals->max();
    if ($priceFloor !== null && $priceCeil !== null && $priceCeil <= $priceFloor) {
        $priceCeil = $priceFloor + 1;
    }
    $priceStep  = ($priceFloor !== null && $priceCeil !== null)
        ? max(10000, (int) (round((($priceCeil - $priceFloor) / 100) / 1000) * 1000))
        : 10000;
    $suburbList = $properties->pluck('suburb')->filter()->unique()->sort()->values();
    $hasScores  = $properties->contains(fn ($p) => (int) ($p->match_score ?? 0) > 0);

    // AT-266 follow-up — the wishlist's free-text `name` is agent-typed and
    // often just "For Sale" regardless of what's actually saved, so it isn't
    // trustworthy as a dropdown title. Build the header from the real saved
    // criteria instead: price range + property type (the two things that
    // actually distinguish one wishlist from another for the same contact).
    $typeLabels = array_map(
        fn ($t) => ucwords(str_replace('_', ' ', (string) $t)),
        $m->propertyTypeList()
    );
    $typeLabel = !empty($typeLabels)
        ? implode(', ', $typeLabels)
        : ($m->category ? ucwords(str_replace('_', ' ', (string) $m->category)) : null);
    $priceLabel = ($m->price_min || $m->price_max) ? $m->priceRangeLabel() : null;

    $headerLabel = trim(implode(' · ', array_filter([$priceLabel, $typeLabel])));
    if ($headerLabel === '') {
        $headerLabel = $m->listingTypeLabel();
    }
@endphp

<div class="match-group-root">
@if($showHeader)
<details class="surface-card overflow-hidden mb-4" {{ $group['isCurrent'] ? 'open' : '' }}>
    <summary class="flex items-center justify-between gap-3 px-5 lg:px-6 py-4 cursor-pointer select-none" style="list-style:none;">
        <div class="flex items-center gap-2 min-w-0">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--brand-icon);"><path stroke-linecap="round" stroke-linejoin="round" d="m21 21-5.197-5.197m0 0A7.5 7.5 0 1 0 5.196 15.803a7.5 7.5 0 0 0 10.607 0Z" /></svg>
            <div class="min-w-0">
                <h2 class="text-base font-bold truncate" style="color: var(--text-primary);">{{ $headerLabel }}</h2>
                <p class="text-xs mt-0.5 truncate" style="color: var(--text-muted);">
                    {{ $m->listingTypeLabel() }}
                    @if(!empty($m->suburbList())) · {{ implode(', ', $m->suburbList()) }} @endif
                </p>
            </div>
        </div>
        <span class="ds-badge ds-badge-info flex-shrink-0">{{ number_format($totalCount) }} match{{ $totalCount === 1 ? '' : 'es' }}</span>
    </summary>
    <div class="px-5 lg:px-6 pb-6 pt-1 space-y-4">
        @include('shared._match-group-body')
    </div>
</details>
@else
    @include('shared._match-group-body')
@endif
</div>
