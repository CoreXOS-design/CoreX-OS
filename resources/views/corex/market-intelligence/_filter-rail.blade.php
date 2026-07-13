{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.3 — Left filter rail with restored filters.

    Sticky: position: sticky, top: <header height>, max-height computed,
    overflow-y: auto so internal content scrolls without breaking page flow.

    Sections (top → bottom):
      search, active filter pills,
      By town, By type, By beds, Demand pockets,
      By price band, By status, By captured by (manager-only),
      By date, By agency

    Every filter emits a canonical legacy controller param so no new
    request-handling logic is needed:
      search                                       (LIKE multi-field)
      suburb                                       (exact)
      property_type                                (exact)
      bedrooms_exact                               (NEW in F.2 — exact)
      price_min, price_max                         (range)
      is_active                                    (1|0|all)
      captured_by                                  (user id)
      date_from                                    (Y-m-d)
      agency_name                                  (LIKE %x%)
      action_preset                                (R5/R6/R3/my_claims/expiring)

    Spec: build-f-market-intelligence-redesign-spec.md §8.3.
--}}
@php
    $agg = $filterRailAggregates ?? ['by_suburb'=>collect(),'by_type'=>collect(),'by_beds'=>collect()];
    $pockets = $demandPockets ?? [];
    $captureUsers = $users ?? collect();  // Reuses the existing $users from controller
    $priceBands = collect($prospectingSetupPriceBandsSale ?? collect())->values();
    $isManager = $isProspectingManager ?? false;

    $activeSuburb = request('suburb');
    $activeType = request('property_type');
    $activeBedsExact = request('bedrooms_exact');
    $activeSearch = request('search', '');
    $activePriceMin = request('price_min');
    $activePriceMax = request('price_max');
    // Status: null = no filter (controller treats absent/all the same), else '1'|'0'|'all'.
    $activeStatus = request('is_active');
    $activeCapturedBy = request('captured_by');
    $activeDateFrom = request('date_from');
    $activeAgencyName = request('agency_name');

    $urlWith = function (array $params) {
        $merged = array_merge(request()->except(['page']), $params);
        foreach ($merged as $k => $v) if ($v === null || $v === '') unset($merged[$k]);
        return route('market-intelligence.work', $merged);
    };
    $urlWithout = function ($keys) {
        $keys = is_array($keys) ? $keys : [$keys];
        return route('market-intelligence.work', request()->except(array_merge($keys, ['page'])));
    };
    $urlClearAll = route('market-intelligence.work');

    // AT-242 / AT-239 — buyer-led prospecting + region.
    $micBuyers     = $micBuyers ?? collect();
    $micRegions    = $micRegions ?? collect();
    $activeBuyerId = request('buyer_id');
    $activeRegion  = request('region');
    $selectedBuyerPill = $selectedBuyer ?? ($activeBuyerId ? $micBuyers->firstWhere('id', (int) $activeBuyerId) : null);
    $buyerLabel = function ($b) {
        $name = trim(($b->first_name ?? '') . ' ' . ($b->last_name ?? ''));
        return $name !== '' ? $name : ($b->email ?? 'Buyer #' . ($b->id ?? '?'));
    };

    // Active filter pills
    $activePills = [];
    if ($selectedBuyerPill)                              $activePills[] = ['label' => 'Buyer · ' . $buyerLabel($selectedBuyerPill), 'remove' => $urlWithout('buyer_id')];
    if ($activeRegion)                                   $activePills[] = ['label' => 'Region · ' . $activeRegion,               'remove' => $urlWithout('region')];
    if ($activeSearch !== '')                            $activePills[] = ['label' => '"' . $activeSearch . '"',                'remove' => $urlWithout('search')];
    if ($activeSuburb)                                   $activePills[] = ['label' => 'Suburb · ' . $activeSuburb,              'remove' => $urlWithout('suburb')];
    if ($activeType)                                     $activePills[] = ['label' => 'Type · ' . $activeType,                  'remove' => $urlWithout('property_type')];
    if ($activeBedsExact !== null && $activeBedsExact !== '') $activePills[] = ['label' => $activeBedsExact . ' bed',           'remove' => $urlWithout('bedrooms_exact')];
    if ($activePriceMin || $activePriceMax) {
        $lo = $activePriceMin ? 'R ' . number_format((int) $activePriceMin / 1000) . 'k' : '0';
        $hi = $activePriceMax ? 'R ' . number_format((int) $activePriceMax / 1000) . 'k' : '∞';
        $activePills[] = ['label' => $lo . ' – ' . $hi,                                                                          'remove' => $urlWithout(['price_min','price_max'])];
    }
    if (in_array($activeStatus, ['0', 0], true))         $activePills[] = ['label' => 'Status · removed',                        'remove' => $urlWithout('is_active')];
    if ($activeCapturedBy) {
        $u = $captureUsers->firstWhere('id', (int) $activeCapturedBy);
        $activePills[] = ['label' => 'Captured · ' . ($u->name ?? '?'),                                                          'remove' => $urlWithout('captured_by')];
    }
    if ($activeDateFrom)                                 $activePills[] = ['label' => 'Since ' . $activeDateFrom,                'remove' => $urlWithout(['date_from','date_to'])];
    if ($activeAgencyName)                               $activePills[] = ['label' => 'Agency · ' . $activeAgencyName,            'remove' => $urlWithout('agency_name')];
    if (request('action_preset'))                        $activePills[] = ['label' => 'Preset · ' . str_replace('_', ' ', request('action_preset')), 'remove' => $urlWithout('action_preset')];

    $sectionTitleStyle = 'font-size: 0.6875rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); padding: 6px 12px 4px;';
    $rowStyle = 'display: flex; justify-content: space-between; align-items: center; padding: 5px 12px; font-size: 0.8125rem; color: var(--text-secondary); text-decoration: none; cursor: pointer;';
    $activeRowStyle = $rowStyle . ' background: color-mix(in srgb, var(--brand-icon) 12%, var(--surface)); color: var(--brand-icon); font-weight: 600;';
@endphp

<aside class="mi-filter-rail">

    {{-- Sticky search at top --}}
    <form method="GET" action="{{ route('market-intelligence.work') }}"
          style="padding: 10px 12px; position: sticky; top: 0; background: var(--surface); border-bottom: 1px solid var(--border); z-index: 2;">
        @foreach(request()->except(['search', 'page']) as $k => $v)
            @if(is_array($v))
                @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach
            @else
                <input type="hidden" name="{{ $k }}" value="{{ $v }}">
            @endif
        @endforeach
        <input type="text" name="search" value="{{ $activeSearch }}"
               placeholder="Search address, agent…"
               class="w-full rounded-md px-2 py-1.5 text-sm"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-size: 0.8125rem;">
    </form>

    {{-- Active filter pills --}}
    @if(!empty($activePills))
    <div style="padding: 8px 12px; border-bottom: 1px solid var(--border);">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 4px; margin-bottom: 4px;">
            <span style="{{ $sectionTitleStyle }}; padding: 0;">Active filters</span>
            @if(count($activePills) > 1)
            <a href="{{ $urlClearAll }}" style="font-size: 0.625rem; color: var(--brand-icon); text-decoration: none;">Clear all</a>
            @endif
        </div>
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

    {{-- AT-242 — Buyer-led prospecting. Pick a buyer → the universe narrows to
         stock matching that buyer's wishlist (canonical Core Matches score),
         strongest first. Searchable client-side over the buyers that have
         matched canvass stock. --}}
    @if($micBuyers->count() > 0)
    <div x-data="{ open: {{ $activeBuyerId ? 'true' : 'false' }}, q: '' }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> Prospect for buyer
        </button>
        <div x-show="open" style="padding: 0 0 6px;">
            <div style="padding: 2px 12px 6px;">
                <input type="text" x-model="q" placeholder="Search buyer…"
                       class="w-full rounded-md px-2 py-1"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary); font-size: 0.8125rem;">
            </div>
            @if($activeBuyerId)
                <a href="{{ $urlWithout('buyer_id') }}" style="{{ $rowStyle }} color: var(--brand-icon);">
                    <span>← All stock (clear buyer)</span>
                </a>
            @endif
            <div style="max-height: 240px; overflow-y: auto;">
                @foreach($micBuyers as $b)
                    @php $bIsActive = (string) $activeBuyerId === (string) $b->id; $bName = $buyerLabel($b); @endphp
                    <a href="{{ $urlWith(['buyer_id' => $b->id]) }}"
                       x-show="q === '' || {{ \Illuminate\Support\Js::from(mb_strtolower($bName . ' ' . ($b->email ?? ''))) }}.includes(q.toLowerCase())"
                       style="{{ $bIsActive ? $activeRowStyle : $rowStyle }}">
                        <span style="min-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $bName }}</span>
                        @if($bIsActive)<span style="font-weight:700;">✓</span>@endif
                    </a>
                @endforeach
            </div>
        </div>
    </div>
    @endif

    {{-- AT-239 — Region. Canonical region from the property spine (towns.region);
         composes AND with every other filter. --}}
    @if($micRegions->count() > 0)
    <div x-data="{ open: {{ $activeRegion ? 'true' : 'false' }} }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By region
        </button>
        <div x-show="open" style="padding: 0 0 6px;">
            @foreach($micRegions as $region)
                @php $rIsActive = (string) $activeRegion === (string) $region; @endphp
                <a href="{{ $rIsActive ? $urlWithout('region') : $urlWith(['region' => $region]) }}"
                   style="{{ $rIsActive ? $activeRowStyle : $rowStyle }}">
                    <span>{{ $region }}</span>
                    @if($rIsActive)<span style="font-weight:700;">×</span>@endif
                </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- By town --}}
    @if($agg['by_suburb']->count() > 0)
    <div x-data="{ open: true, showAll: false }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By town
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
                    <button @click="showAll = true" type="button" style="font-size: 0.6875rem; color: var(--brand-icon); background: none; border: none; cursor: pointer; padding: 0;">
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
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By type
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

    {{-- By beds (exact) --}}
    @if($agg['by_beds']->count() > 0)
    <div x-data="{ open: true }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By beds
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
    <div x-data="{ open: true }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> Demand pockets
        </button>
        <div x-show="open">
            @foreach($pockets as $p)
            @php
                $isActive = $activeSuburb === $p['suburb'] && (string) $activeBedsExact === (string) $p['bedrooms'];
                $href = $isActive
                    ? $urlWithout(['suburb','bedrooms_exact'])
                    : $urlWith(['suburb' => $p['suburb'], 'bedrooms_exact' => $p['bedrooms']]);
            @endphp
            <a href="{{ $href }}"
               style="{{ $isActive ? $activeRowStyle : $rowStyle }} flex-direction: column; align-items: flex-start; gap: 2px;"
               title="{{ $p['buyer_count'] }} strong-tier buyers vs {{ $p['listing_count'] }} listings — ratio {{ $p['ratio'] ?? '∞' }}">
                <span>{{ $p['suburb'] }} · {{ $p['bedrooms'] }} bed</span>
                <span style="font-size: 0.6875rem; color: var(--text-muted);">{{ $p['buyer_count'] }}b / {{ $p['listing_count'] }}l</span>
            </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- By price band (sale bands from prospecting setup) --}}
    @if($priceBands->count() > 0)
    <div x-data="{ open: false }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By price
        </button>
        <div x-show="open" x-cloak>
            @foreach($priceBands as $band)
                @php
                    $isActive = (string) $activePriceMin === (string) ($band->price_min ?? '')
                              && (string) $activePriceMax === (string) ($band->price_max ?? '');
                    $href = $isActive
                        ? $urlWithout(['price_min','price_max'])
                        : $urlWith([
                            'price_min' => $band->price_min ?? '',
                            'price_max' => $band->price_max ?? '',
                        ]);
                    $bandLabel = $band->name ?: ('R ' . number_format($band->price_min / 1000) . 'k – ' .
                        ($band->price_max ? 'R ' . number_format($band->price_max / 1000) . 'k' : '∞'));
                @endphp
                <a href="{{ $href }}" style="{{ $isActive ? $activeRowStyle : $rowStyle }}">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $bandLabel }}</span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- By status (is_active) --}}
    <div x-data="{ open: false }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By status
        </button>
        <div x-show="open" x-cloak>
            @foreach([['1','Active'],['0','Removed'],['all','All']] as [$val, $lbl])
                @php $isActive = (string) $activeStatus === (string) $val; @endphp
                <a href="{{ $urlWith(['is_active' => $val]) }}" style="{{ $isActive ? $activeRowStyle : $rowStyle }}">
                    <span>{{ $lbl }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- By captured by — manager-only --}}
    @if($isManager && $captureUsers->count() > 0)
    <div x-data="{ open: false }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By captured by
        </button>
        <div x-show="open" x-cloak>
            @foreach($captureUsers as $u)
                @php $isActive = (string) $activeCapturedBy === (string) $u->id; @endphp
                <a href="{{ $isActive ? $urlWithout('captured_by') : $urlWith(['captured_by' => $u->id]) }}"
                   style="{{ $isActive ? $activeRowStyle : $rowStyle }}">
                    <span style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">{{ $u->name }}</span>
                </a>
            @endforeach
        </div>
    </div>
    @endif

    {{-- By date --}}
    <div x-data="{ open: false }" style="border-bottom: 1px solid var(--border);">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By date
        </button>
        <div x-show="open" x-cloak>
            @php
                $today = now()->format('Y-m-d');
                $dateChips = [
                    ['Last 7 days', now()->subDays(7)->format('Y-m-d')],
                    ['Last 30 days', now()->subDays(30)->format('Y-m-d')],
                    ['Last 90 days', now()->subDays(90)->format('Y-m-d')],
                ];
            @endphp
            @foreach($dateChips as [$label, $from])
                @php $isActive = $activeDateFrom === $from; @endphp
                <a href="{{ $isActive ? $urlWithout(['date_from','date_to']) : $urlWith(['date_from' => $from]) }}"
                   style="{{ $isActive ? $activeRowStyle : $rowStyle }}">
                    <span>{{ $label }}</span>
                </a>
            @endforeach
        </div>
    </div>

    {{-- By agency (substring filter on agency_name) --}}
    <div x-data="{ open: false }">
        <button @click="open = !open" type="button" style="{{ $sectionTitleStyle }}; width: 100%; text-align: left; background: none; border: none; cursor: pointer; padding: 8px 12px;">
            <span x-text="open ? '▾' : '▸'" style="display: inline-block; width: 12px;"></span> By agency
        </button>
        <div x-show="open" x-cloak style="padding: 6px 12px;">
            <form method="GET" action="{{ route('market-intelligence.work') }}">
                @foreach(request()->except(['agency_name', 'page']) as $k => $v)
                    @if(is_array($v))
                        @foreach($v as $vv)<input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">@endforeach
                    @else
                        <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                    @endif
                @endforeach
                <input type="text" name="agency_name" value="{{ $activeAgencyName }}"
                       placeholder="Filter by agency name…"
                       class="w-full rounded-md px-2 py-1.5 text-xs"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            </form>
        </div>
    </div>
</aside>
