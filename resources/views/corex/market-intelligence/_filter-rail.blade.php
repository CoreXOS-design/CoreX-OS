{{--
    F.2 — Left filter rail.

    Sections (top → bottom): search, active filter pills, by town, by type,
    by beds, demand pockets. Each clickable row toggles a URL param that
    matches the existing controller filter (no new filter names except
    bedrooms_exact, which the controller now handles).

    Param mapping:
      search          → existing controller param
      suburb          → existing (exact)
      property_type   → existing (exact)
      bedrooms_exact  → NEW F.2 param (single-segment exact match)
      Demand pocket   → combined suburb + bedrooms_exact

    Spec: build-f-market-intelligence-redesign-spec.md §8.3.
--}}
@php
    $agg = $filterRailAggregates ?? ['by_suburb'=>collect(),'by_type'=>collect(),'by_beds'=>collect()];
    $pockets = $demandPockets ?? [];

    $activeSuburb = request('suburb');
    $activeType = request('property_type');
    $activeBedsExact = request('bedrooms_exact');
    $activeSearch = request('search', '');

    // URL builders preserving other params and discarding pagination cursor.
    $urlWith = function (array $params) {
        $merged = array_merge(request()->except(['page']), $params);
        // Remove keys with null values so they drop out of URL.
        foreach ($merged as $k => $v) if ($v === null) unset($merged[$k]);
        return route('market-intelligence.index', $merged);
    };
    $urlWithout = function (string $key) {
        return route('market-intelligence.index', request()->except([$key, 'page']));
    };

    // Active-filter pills set
    $activePills = [];
    if ($activeSuburb)    $activePills[] = ['label' => 'Suburb · ' . $activeSuburb, 'remove' => $urlWithout('suburb')];
    if ($activeType)      $activePills[] = ['label' => 'Type · ' . $activeType, 'remove' => $urlWithout('property_type')];
    if ($activeBedsExact !== null && $activeBedsExact !== '') $activePills[] = ['label' => $activeBedsExact . ' bed', 'remove' => $urlWithout('bedrooms_exact')];
    if (request('action_preset')) $activePills[] = ['label' => 'Preset · ' . str_replace('_', ' ', request('action_preset')), 'remove' => $urlWithout('action_preset')];

    $sectionTitleStyle = 'font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); padding: 6px 12px 4px;';
    $rowStyle = 'display: flex; justify-content: space-between; align-items: center; padding: 5px 12px; font-size: 0.8125rem; color: var(--text-secondary); text-decoration: none; cursor: pointer;';
    $activeRowStyle = $rowStyle . ' background: color-mix(in srgb, var(--brand-icon) 12%, var(--surface)); color: var(--brand-icon); font-weight: 600;';
@endphp

<aside class="mi-filter-rail" x-data="{ railOpen: window.innerWidth >= 1024 }"
       style="width: 200px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); overflow-y: auto; position: sticky; top: 0; max-height: calc(100vh - 60px);">

    {{-- Sticky search at top --}}
    <form method="GET" action="{{ route('market-intelligence.index') }}"
          style="padding: 10px 12px; position: sticky; top: 0; background: var(--surface); border-bottom: 1px solid var(--border); z-index: 2;">
        @foreach(request()->except(['search', 'page']) as $k => $v)
            @if(is_array($v))
                @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach
            @else
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endif
        @endforeach
        <input type="text"
               name="search"
               value="{{ $activeSearch }}"
               placeholder="Search address, agent…"
               class="w-full rounded-md px-2 py-1.5 text-sm"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-size: 0.8125rem;">
    </form>

    {{-- Active filter pills --}}
    @if(!empty($activePills))
    <div style="padding: 8px 12px; border-bottom: 1px solid var(--border);">
        <div style="{{ $sectionTitleStyle }}; padding: 0 0 4px 0;">Active filters</div>
        <div style="display: flex; flex-wrap: wrap; gap: 4px;">
            @foreach($activePills as $pill)
            <a href="{{ $pill['remove'] }}"
               class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-semibold no-underline"
               style="background: color-mix(in srgb, var(--brand-icon) 14%, transparent); color: var(--brand-icon); border: 1px solid currentColor;"
               title="Remove this filter">
                {{ $pill['label'] }}
                <span style="font-weight: 700;">×</span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- By town (top 8 + expand) --}}
    @if($agg['by_suburb']->count() > 0)
    <div x-data="{ open: true, showAll: false }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button"
                style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span>
            By town
        </button>
        <div x-show="open">
            @foreach($agg['by_suburb']->take(8) as $row)
            <a href="{{ $activeSuburb === $row->suburb ? $urlWithout('suburb') : $urlWith(['suburb' => $row->suburb]) }}"
               style="{{ $activeSuburb === $row->suburb ? $activeRowStyle : $rowStyle }}">
                <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row->suburb }}</span>
                <span style="color: var(--text-muted); font-size: 0.6875rem; flex-shrink: 0;">{{ number_format($row->c) }}</span>
            </a>
            @endforeach
            @if($agg['by_suburb']->count() > 8)
                <div x-show="!showAll" style="padding: 4px 12px;">
                    <button @click="showAll = true" type="button"
                            style="font-size: 0.6875rem; color: var(--brand-icon); background: none; border: none; cursor: pointer; padding: 0;">
                        + {{ $agg['by_suburb']->count() - 8 }} more
                    </button>
                </div>
                <div x-show="showAll" x-cloak>
                    @foreach($agg['by_suburb']->slice(8) as $row)
                    <a href="{{ $activeSuburb === $row->suburb ? $urlWithout('suburb') : $urlWith(['suburb' => $row->suburb]) }}"
                       style="{{ $activeSuburb === $row->suburb ? $activeRowStyle : $rowStyle }}">
                        <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $row->suburb }}</span>
                        <span style="color: var(--text-muted); font-size: 0.6875rem;">{{ number_format($row->c) }}</span>
                    </a>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- By type --}}
    @if($agg['by_type']->count() > 0)
    <div x-data="{ open: true }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button"
                style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span>
            By type
        </button>
        <div x-show="open">
            @foreach($agg['by_type'] as $row)
            <a href="{{ $activeType === $row->property_type ? $urlWithout('property_type') : $urlWith(['property_type' => $row->property_type]) }}"
               style="{{ $activeType === $row->property_type ? $activeRowStyle : $rowStyle }}">
                <span>{{ $row->property_type }}</span>
                <span style="color: var(--text-muted); font-size: 0.6875rem;">{{ number_format($row->c) }}</span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- By beds (exact match) --}}
    @if($agg['by_beds']->count() > 0)
    <div x-data="{ open: true }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button"
                style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span>
            By beds
        </button>
        <div x-show="open">
            @foreach($agg['by_beds']->where('bedrooms', '>', 0)->take(7) as $row)
            @php $isActive = (string) $activeBedsExact === (string) $row->bedrooms; @endphp
            <a href="{{ $isActive ? $urlWithout('bedrooms_exact') : $urlWith(['bedrooms_exact' => $row->bedrooms]) }}"
               style="{{ $isActive ? $activeRowStyle : $rowStyle }}">
                <span>{{ $row->bedrooms }} bed</span>
                <span style="color: var(--text-muted); font-size: 0.6875rem;">{{ number_format($row->c) }}</span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- Demand pockets --}}
    @if(!empty($pockets))
    <div x-data="{ open: true }">
        <button @click="open = !open" type="button"
                style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span>
            Demand pockets
        </button>
        <div x-show="open">
            @foreach($pockets as $p)
            @php
                $isActive = $activeSuburb === $p['suburb'] && (string) $activeBedsExact === (string) $p['bedrooms'];
                $href = $isActive
                    ? route('market-intelligence.index', request()->except(['suburb', 'bedrooms_exact', 'page']))
                    : $urlWith(['suburb' => $p['suburb'], 'bedrooms_exact' => $p['bedrooms']]);
            @endphp
            <a href="{{ $href }}"
               style="{{ $isActive ? $activeRowStyle : $rowStyle }} flex-direction: column; align-items: flex-start; gap: 2px;"
               title="{{ $p['buyer_count'] }} strong-tier buyers vs {{ $p['listing_count'] }} listings — ratio {{ $p['ratio'] ?? '∞' }}">
                <span>{{ $p['suburb'] }} · {{ $p['bedrooms'] }} bed</span>
                <span style="font-size: 0.6875rem; color: var(--text-muted);">
                    {{ $p['buyer_count'] }}b / {{ $p['listing_count'] }}l
                </span>
            </a>
            @endforeach
        </div>
    </div>
    @endif
</aside>
