{{-- Prospecting Intelligence — Summary Block
     Consumes $snapshot (IntelligenceSnapshot), $filters, $segmentLabels.
     Server-rendered, URL-state encoded (spec P5). No JS.

     Styled with CoreX CSS custom properties (var(--surface), var(--text-primary),
     etc.) — matches the existing prospecting / settings pages. Theme switching
     is handled at the token layer; no Tailwind dark: variants needed.
--}}

@php
    use App\Support\Prospecting\IntelligenceSnapshot;
    /** @var IntelligenceSnapshot $snapshot */
    /** @var array $filters */
    /** @var array $segmentLabels */

    $appliedListingType = $filters['listing_type'] ?? 'sale';

    // Filter-aware URL builders. `agency_id` is server-derived and never
    // exposed in the URL. DateTimeInterface values are re-serialised as
    // 'Y-m-d' so they round-trip through the controller's parser cleanly
    // (http_build_query on raw DateTimeImmutable emits opaque object props).
    $serialiseFilters = function (array $filters): array {
        $out = [];
        foreach ($filters as $k => $v) {
            if ($v === null || $v === '') continue;
            if ($k === 'agency_id') continue;
            if ($v instanceof \DateTimeInterface) {
                $out[$k] = $v->format('Y-m-d');
                continue;
            }
            if (is_array($v)) {
                $out[$k] = array_values($v);
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    };
    $urlWith = function (array $patch) use ($filters, $serialiseFilters) {
        $merged = $serialiseFilters(array_merge($filters, $patch));
        return route('prospecting.index') . ($merged ? '?' . http_build_query($merged) : '');
    };
    $urlWithout = function (string $key) use ($filters, $serialiseFilters) {
        $new = $filters;
        unset($new[$key]);
        $merged = $serialiseFilters($new);
        return route('prospecting.index') . ($merged ? '?' . http_build_query($merged) : '');
    };

    // Applied filter chips
    $appliedChips = [];
    if (!empty($filters['town_id'])) {
        $town = $segmentLabels['towns']->get($filters['town_id']);
        if ($town) $appliedChips[] = ['key' => 'town_id', 'label' => "Town: {$town->name}"];
    }
    if (!empty($filters['property_type_slug'])) {
        $type = $segmentLabels['propertyTypes']->firstWhere('slug', $filters['property_type_slug']);
        if ($type) $appliedChips[] = ['key' => 'property_type_slug', 'label' => "Type: {$type->name}"];
    }
    if (!empty($filters['bedroom_segment_id'])) {
        $seg = $segmentLabels['bedroomSegments']->get($filters['bedroom_segment_id']);
        if ($seg) $appliedChips[] = ['key' => 'bedroom_segment_id', 'label' => "Beds: {$seg->name}"];
    }
    if (!empty($filters['price_band_id'])) {
        $bandKey = $appliedListingType === 'rental' ? 'priceBandsRental' : 'priceBandsSale';
        $band = $segmentLabels[$bandKey]->get($filters['price_band_id']);
        if ($band) $appliedChips[] = ['key' => 'price_band_id', 'label' => "Price: {$band->name}"];
    }
    if (!empty($filters['suburb_normalised'])) {
        $appliedChips[] = ['key' => 'suburb_normalised', 'label' => 'Suburb: ' . ucwords((string) $filters['suburb_normalised'])];
    }
    if (!empty($filters['unmapped_only'])) {
        $appliedChips[] = ['key' => 'unmapped_only', 'label' => 'Unmapped suburbs only'];
    }
    if (!empty($filters['sources'])) {
        $appliedChips[] = ['key' => 'sources', 'label' => 'Source: ' . strtoupper(implode(', ', (array) $filters['sources']))];
    }
    if (!empty($filters['buyer_state'])) {
        $appliedChips[] = ['key' => 'buyer_state', 'label' => 'Status: ' . ucfirst((string) $filters['buyer_state'])];
    }
    if (!empty($filters['buyers_since']) && $filters['buyers_since'] instanceof \DateTimeInterface) {
        $appliedChips[] = ['key' => 'buyers_since', 'label' => 'Since: ' . $filters['buyers_since']->format('j M Y')];
    }
@endphp

{{-- Regeneration banner — sits above everything, applies to all page states.
     Source: wishlist Prompt 09's cache flag (corex.matches.regenerating). --}}
@if(!empty($regenerating))
    @include('prospecting._empty-state', ['kind' => 'regenerating'])
@endif

{{-- No-data banner — when the agency has zero listings AND zero buyers AND
     no user-filters applied. Distinguishes brand-new agency setup from a
     filtered-to-empty drill-down. listing_type alone doesn't count as a
     filter (it has a default). --}}
@php
    $hasNoData = $snapshot->activeListings === 0
        && $snapshot->activeBuyers === 0
        && count($appliedChips) === 0;
@endphp
@if($hasNoData)
    @include('prospecting._empty-state', ['kind' => 'no_data'])
@endif

{{-- Headline row --}}
<div class="grid grid-cols-1 md:grid-cols-3 gap-4">

    <a href="{{ $urlWith(['focus' => 'buyers']) }}"
       class="block rounded-md p-5 no-underline transition hover:brightness-105"
       style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Active Buyers</div>
        <div class="text-3xl font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($snapshot->activeBuyers) }}</div>
        <div class="text-[11px] mt-2" style="color: var(--text-muted);">new + warm with at least one active wishlist</div>
    </a>

    <a href="{{ $urlWith(['focus' => 'listings']) }}"
       class="block rounded-md p-5 no-underline transition hover:brightness-105"
       style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Prospecting Listings</div>
        <div class="text-3xl font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($snapshot->activeListings) }}</div>
        <div class="text-[11px] mt-2" style="color: var(--text-muted);">from all portal sources, agency-scoped</div>
    </a>

    <div class="block rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <div class="text-xs uppercase tracking-wider" style="color: var(--text-muted);">Your Open Mandates</div>
        <div class="text-3xl font-semibold mt-1" style="color: var(--text-primary);">{{ number_format($snapshot->openMandates) }}</div>
        <div class="text-[11px] mt-2" style="color: var(--text-muted);">stock you can match against demand</div>
    </div>

</div>

{{-- Applied filter chips --}}
@if(count($appliedChips) > 0)
    <div class="flex flex-wrap items-center gap-2 rounded-md px-3 py-2"
         style="background: color-mix(in srgb, var(--ds-amber, #f59e0b) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent);">
        <span class="text-[10px] uppercase tracking-wider font-semibold" style="color: var(--ds-amber, #b45309);">Filters:</span>
        @foreach($appliedChips as $chip)
            <a href="{{ $urlWithout($chip['key']) }}"
               class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] no-underline transition hover:brightness-105"
               style="background: var(--surface); color: var(--text-primary); border: 1px solid var(--border);">
                <span>{{ $chip['label'] }}</span>
                <span class="font-bold leading-none">×</span>
            </a>
        @endforeach
        <a href="{{ route('prospecting.index') }}"
           class="ml-auto text-[11px] no-underline hover:underline"
           style="color: var(--ds-amber, #b45309);">Clear all</a>
    </div>
@endif

{{-- Stock-gap callout (towns with demand surplus) --}}
@php
    $gapsWithDemand = collect($snapshot->stockGap['town'] ?? [])
        ->filter(fn ($g) => $g['gap'] > 0)
        ->take(5);
@endphp
@if($gapsWithDemand->isNotEmpty())
    <div class="rounded-md p-4"
         style="background: color-mix(in srgb, #10b981 10%, transparent); border: 1px solid color-mix(in srgb, #10b981 25%, transparent);">
        <div class="mb-3">
            <div class="text-sm font-semibold" style="color: #047857;">Demand exceeds supply</div>
            <div class="text-[11px] mt-0.5" style="color: var(--text-muted);">
                Towns where you have more buyers waiting than listings available — your prospecting opportunities
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            @foreach($gapsWithDemand as $row)
                @php
                    $townMatch = $segmentLabels['towns']->firstWhere('name', $row['label']);
                    $townId = $townMatch?->id;
                @endphp
                @if($townId)
                    <a href="{{ $urlWith(['town_id' => $townId]) }}"
                       class="inline-flex items-center gap-2 px-3 py-2 rounded no-underline transition hover:brightness-105"
                       style="background: var(--surface); border: 1px solid color-mix(in srgb, #10b981 35%, transparent);">
                        <span class="font-medium text-sm" style="color: var(--text-primary);">{{ $row['label'] }}</span>
                        <span class="text-[11px]" style="color: var(--text-muted);">{{ $row['buyers'] }} buyer{{ $row['buyers'] === 1 ? '' : 's' }} · {{ $row['listings'] }} listing{{ $row['listings'] === 1 ? '' : 's' }}</span>
                        <span class="px-1.5 py-0.5 rounded text-[10px] font-bold"
                              style="background: color-mix(in srgb, #10b981 20%, transparent); color: #047857;">+{{ $row['gap'] }}</span>
                    </a>
                @else
                    <span class="inline-flex items-center gap-2 px-3 py-2 rounded"
                          style="background: var(--surface-2); border: 1px dashed var(--border);"
                          title="No drill-down available — town not in your mapping">
                        <span class="font-medium text-sm" style="color: var(--text-secondary);">{{ $row['label'] }}</span>
                        <span class="text-[11px]" style="color: var(--text-muted);">{{ $row['buyers'] }}b · {{ $row['listings'] }}l · +{{ $row['gap'] }}</span>
                    </span>
                @endif
            @endforeach
        </div>
    </div>
@endif

{{-- Four segment grids --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">

    @include('prospecting._segment-grid', [
        'title' => 'By Town',
        'dimension' => 'town',
        'buyerRows' => $snapshot->buyerSegments['town'] ?? [],
        'listingRows' => $snapshot->listingSegments['town'] ?? [],
        'urlBuilder' => fn ($row) => $row['id'] ? $urlWith(['town_id' => $row['id']]) : null,
        'activeId' => $filters['town_id'] ?? null,
    ])

    @include('prospecting._segment-grid', [
        'title' => 'By Property Type',
        'dimension' => 'property_type',
        'buyerRows' => $snapshot->buyerSegments['property_type'] ?? [],
        'listingRows' => $snapshot->listingSegments['property_type'] ?? [],
        'urlBuilder' => function ($row) use ($urlWith, $segmentLabels) {
            if (!$row['id']) return null;
            $type = $segmentLabels['propertyTypes']->get($row['id']);
            return $type ? $urlWith(['property_type_slug' => $type->slug]) : null;
        },
        'activeId' => (function () use ($filters, $segmentLabels) {
            if (empty($filters['property_type_slug'])) return null;
            return $segmentLabels['propertyTypes']->firstWhere('slug', $filters['property_type_slug'])?->id;
        })(),
    ])

    @include('prospecting._segment-grid', [
        'title' => 'By Bedrooms',
        'dimension' => 'bedrooms',
        'buyerRows' => $snapshot->buyerSegments['bedrooms'] ?? [],
        'listingRows' => $snapshot->listingSegments['bedrooms'] ?? [],
        'urlBuilder' => fn ($row) => $row['id'] ? $urlWith(['bedroom_segment_id' => $row['id']]) : null,
        'activeId' => $filters['bedroom_segment_id'] ?? null,
    ])

    @include('prospecting._segment-grid', [
        'title' => 'By Price Band (' . ucfirst($appliedListingType) . ')',
        'dimension' => 'price_band',
        'buyerRows' => $snapshot->buyerSegments['price_band'] ?? [],
        'listingRows' => $snapshot->listingSegments['price_band'] ?? [],
        'urlBuilder' => fn ($row) => $row['id'] ? $urlWith(['price_band_id' => $row['id']]) : null,
        'activeId' => $filters['price_band_id'] ?? null,
        'switcherHtml' => view('prospecting._listing-type-switcher', ['current' => $appliedListingType, 'urlWith' => $urlWith])->render(),
    ])

</div>

{{-- Buyer Funnel — sits after the four segment grids; consumes $snapshot,
     $filters, and the in-scope $urlWith closure from above. --}}
@include('prospecting._buyer-funnel')
