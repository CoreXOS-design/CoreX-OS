{{-- Prospecting Intelligence — Reusable segment grid
     Renders one dimension (town / property_type / bedrooms / price_band) as
     two rows: Buyers tiles + Listings tiles. Each tile clickable when it has
     a resolvable filter URL; unmapped/null-id tiles render dashed-border,
     non-clickable.

     Props: $title, $buyerRows, $listingRows, $urlBuilder (closure), $activeId, $switcherHtml? --}}

<div class="rounded-md p-4" style="background: var(--surface); border: 1px solid var(--border);">

    <div class="flex items-center justify-between mb-3">
        <h3 class="text-xs font-semibold uppercase tracking-wider" style="color: var(--text-secondary);">{{ $title }}</h3>
        @isset($switcherHtml)
            {!! $switcherHtml !!}
        @endisset
    </div>

    {{-- Buyers row --}}
    <div class="mb-3">
        <div class="text-[10px] uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">
            Buyers ({{ collect($buyerRows)->sum('count') }})
        </div>
        <div class="flex flex-wrap gap-1.5">
            @forelse($buyerRows as $row)
                @php $url = $urlBuilder($row); $isActive = ($activeId !== null && $row['id'] === $activeId); @endphp
                @if($url)
                    <a href="{{ $url }}"
                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs no-underline transition hover:brightness-110"
                       @if($isActive)
                           style="background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);"
                       @else
                           style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
                       @endif>
                        <span>{{ $row['label'] }}</span>
                        <span class="font-bold">{{ $row['count'] }}</span>
                    </a>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs"
                          style="background: var(--surface-2); color: var(--text-muted); border: 1px dashed var(--border);"
                          title="{{ $row['label'] }} — no drill-down (unmapped or any-value bucket)">
                        <span>{{ $row['label'] }}</span>
                        <span class="font-bold">{{ $row['count'] }}</span>
                    </span>
                @endif
            @empty
                <span class="text-[11px]" style="color: var(--text-muted);">No buyers</span>
            @endforelse
        </div>
    </div>

    {{-- Listings row --}}
    <div>
        <div class="text-[10px] uppercase tracking-wider mb-1.5" style="color: var(--text-muted);">
            Listings ({{ collect($listingRows)->sum('count') }})
        </div>
        <div class="flex flex-wrap gap-1.5">
            @forelse($listingRows as $row)
                @php $url = $urlBuilder($row); $isActive = ($activeId !== null && $row['id'] === $activeId); @endphp
                @if($url)
                    <a href="{{ $url }}"
                       class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs no-underline transition hover:brightness-110"
                       @if($isActive)
                           style="background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);"
                       @else
                           style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);"
                       @endif>
                        <span>{{ $row['label'] }}</span>
                        <span class="font-bold">{{ $row['count'] }}</span>
                    </a>
                @else
                    <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded text-xs"
                          style="background: var(--surface-2); color: var(--text-muted); border: 1px dashed var(--border);"
                          title="{{ $row['label'] }} — no drill-down">
                        <span>{{ $row['label'] }}</span>
                        <span class="font-bold">{{ $row['count'] }}</span>
                    </span>
                @endif
            @empty
                <span class="text-[11px]" style="color: var(--text-muted);">No listings</span>
            @endforelse
        </div>
    </div>

</div>
