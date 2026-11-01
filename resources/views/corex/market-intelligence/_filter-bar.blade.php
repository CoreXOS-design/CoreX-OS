{{--
    MIC Work tab top filter bar (mic-work-tab.md 13.2). Replaces the left column: the quick filters on the first row, everything else under "More filters"
    (the old groups, the buyer-match legend and the ticks are still all here - they were moved, not removed), the active filters as chips, and at the right
    the sort and the Adverts | List switch. Every filter lives in the URL.

    Inputs: $filterRailAggregates, $suburbOptions, $users, $micBuyers, $micRegions, $selectedBuyer, $isProspectingManager, $layout.
--}}
@php
    $agg = $filterRailAggregates ?? ['by_suburb' => collect(), 'by_type' => collect(), 'by_beds' => collect()];
    // The filters in force: the URL's own, else this user's remembered set (cc4's MicUserPreferences, shared by the controller as $micFilters).
    $F = array_filter($micFilters ?? [], fn ($v) => $v !== '' && $v !== null && $v !== []);
    $keys = \App\Services\Prospecting\MicWork\MicAdvertFilters::KEYS;
    $selSuburbs = array_values(array_filter(array_map('intval', (array) ($F['suburbs'] ?? []))));
    $selTypes = array_values(array_filter((array) ($F['type'] ?? []), fn ($t) => trim((string) $t) !== ''));
    // A link that changes the new filters: it carries the WHOLE set explicitly (a URL with none would fall back to the remembered set), and says clear=1 when nothing is left.
    $fLink = function (array $changes) use ($F, $keys) {
        $set = array_filter(array_merge($F, $changes), fn ($v) => $v !== '' && $v !== null && $v !== []);
        $q = array_merge(request()->except(array_merge($keys, ['page', 'clear', 'reset'])), $set);
        if (!array_intersect_key($set, array_flip($keys))) { $q['clear'] = 1; }
        return route('market-intelligence.work', $q);
    };
    // The old filters live in the URL only: these keep whatever the URL carries.
    $urlWith = function (array $params) {
        $merged = array_merge(request()->except(['page']), $params);
        foreach ($merged as $k => $v) { if ($v === null || $v === '' || $v === []) { unset($merged[$k]); } }
        return route('market-intelligence.work', $merged);
    };
    $urlWithout = function ($keys2) {
        $keys2 = is_array($keys2) ? $keys2 : [$keys2];
        return route('market-intelligence.work', request()->except(array_merge($keys2, ['page'])));
    };
    $view = $micViewChoice ?? 'adverts';
    $layout = $view;

    // ── active filter chips: one per filter, each with its own × ──────────────────────────────────────
    $chips = [];
    $suburbName = function ($id) use ($suburbOptions) { foreach (array_merge($suburbOptions['working'] ?? [], $suburbOptions['others'] ?? []) as $o) { if ($o['id'] === $id) { return $o['name']; } } return '#' . $id; };
    if (request('search') !== null && trim((string) request('search')) !== '') { $chips[] = ['"' . trim((string) request('search')) . '"', $urlWithout('search')]; }
    foreach ($selSuburbs as $sid) { $chips[] = ['Suburb · ' . $suburbName($sid), $fLink(['suburbs' => array_values(array_diff($selSuburbs, [$sid]))])]; }
    foreach ($selTypes as $t) { $chips[] = ['Type · ' . $t, $fLink(['type' => array_values(array_diff($selTypes, [$t]))])]; }
    if (!empty($F['beds'])) { $chips[] = [$F['beds'] . '+ beds', $fLink(['beds' => null])]; }
    if (!empty($F['price_min']) || !empty($F['price_max'])) {
        $lo = !empty($F['price_min']) ? 'R ' . number_format((int) $F['price_min']) : 'any'; $hi = !empty($F['price_max']) ? 'R ' . number_format((int) $F['price_max']) : 'any';
        $chips[] = ['Price · ' . $lo . ' – ' . $hi, $fLink(['price_min' => null, 'price_max' => null])];
    }
    if (($F['show_status'] ?? '') === 'all') { $chips[] = ['Under offer / sold shown', $fLink(['show_status' => null])]; }
    if (!empty($F['owner_only'])) { $chips[] = ['Owner advertised', $fLink(['owner_only' => null])]; }
    if (!empty($F['sole_mandate_only'])) { $chips[] = ['Sole mandate', $fLink(['sole_mandate_only' => null])]; }
    if (in_array($F['claimed'] ?? '', ['unclaimed', 'mine', 'others'], true)) { $chips[] = ['Claimed · ' . $F['claimed'], $fLink(['claimed' => null])]; }
    if (request('property_type')) { $chips[] = ['Type · ' . request('property_type'), $urlWithout('property_type')]; }
    if (request('suburb')) { $chips[] = ['Suburb · ' . request('suburb'), $urlWithout('suburb')]; }
    if (request('bedrooms_exact') !== null && request('bedrooms_exact') !== '') { $chips[] = [request('bedrooms_exact') . ' bed', $urlWithout('bedrooms_exact')]; }
    if (request('region')) { $r = collect($micRegions ?? [])->firstWhere('value', request('region')); $chips[] = ['Region · ' . ($r->label ?? request('region')), $urlWithout('region')]; }
    if (!empty($selectedBuyer)) { $chips[] = ['Buyer · ' . (trim(($selectedBuyer->first_name ?? '') . ' ' . ($selectedBuyer->last_name ?? '')) ?: 'Buyer'), $urlWithout('buyer_id')]; }
    if (request()->boolean('address_filter') || request('address_filter') === 'with_address') { $chips[] = ['With address only', $urlWithout('address_filter')]; }
    if (request()->boolean('include_in_stock')) { $chips[] = ['Our stock shown', $urlWithout('include_in_stock')]; }
    if (!request()->boolean('p24', true)) { $chips[] = ['P24 hidden', $urlWithout('p24')]; }
    if (!request()->boolean('pp', true)) { $chips[] = ['PP hidden', $urlWithout('pp')]; }
    if (in_array(request('is_active'), ['0', 0], true)) { $chips[] = ['Status · removed', $urlWithout('is_active')]; }
    if (request('source') && isset(\App\Services\Prospecting\PropertySourceFacts::FILTERS[request('source')])) { $chips[] = ['Source · ' . \App\Services\Prospecting\PropertySourceFacts::FILTERS[request('source')], $urlWithout('source')]; }
    if (request('captured_by')) { $u = collect($users ?? [])->firstWhere('id', (int) request('captured_by')); $chips[] = ['Captured · ' . ($u->name ?? '?'), $urlWithout('captured_by')]; }
    if (request('date_from')) { $chips[] = ['Since ' . request('date_from'), $urlWithout(['date_from', 'date_to'])]; }
    if (request('agency_name')) { $chips[] = ['Agency · ' . request('agency_name'), $urlWithout('agency_name')]; }
    if (request('action_preset')) { $chips[] = ['Preset · ' . str_replace('_', ' ', request('action_preset')), $urlWithout('action_preset')]; }
    if (request('tile')) { $chips[] = ['Tile · ' . (\App\Services\Prospecting\MicWork\MicWorkTiles::TILES[\App\Services\Prospecting\MicWork\MicWorkTiles::numberForKey(request('tile'))][3] ?? request('tile')), $urlWithout('tile')]; }

    // "More filters" opens by itself when one of its filters is on
    $moreKeys = ['address_filter', 'include_in_stock', 'region', 'buyer_id', 'buyer_scope', 'source', 'captured_by', 'date_from', 'agency_name', 'is_active', 'score_min', 'score_max', 'action_preset'];
    $moreNew = collect(['show_status', 'owner_only', 'sole_mandate_only', 'claimed'])->filter(fn ($k) => !empty($F[$k]) && !($k === 'claimed' && $F[$k] === 'any'))->count();
    $moreOn = $moreNew > 0 || collect($moreKeys)->contains(fn ($k) => request()->filled($k)) || !request()->boolean('p24', true) || !request()->boolean('pp', true);
    $moreCount = $moreNew + collect($moreKeys)->filter(fn ($k) => request()->filled($k))->count() + (!request()->boolean('p24', true) ? 1 : 0) + (!request()->boolean('pp', true) ? 1 : 0);

    $hidden = collect(request()->except(array_merge($keys, ['search', 'page', 'view', 'clear', 'reset', '_fragments'])))->filter(fn ($v) => !is_array($v));
    $carry = collect(array_intersect_key($F, array_flip(['show_status', 'owner_only', 'sole_mandate_only', 'claimed'])));
    $box = 'height: 30px; padding: 0 8px; font-size: 0.8125rem; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2); color: var(--text-primary);';
    $sortNow = request('sort', \App\Services\Prospecting\MicWork\MicWorkList::hasScores() ? 'score' : 'last_seen_at') . '|' . (request('dir') === 'asc' ? 'asc' : 'desc');
    $sortOptions = [
        'score|desc' => 'Opportunity score', 'price|desc' => 'Price ↓', 'price|asc' => 'Price ↑', 'first_seen_at|desc' => 'Newest', 'first_seen_at|asc' => 'Days on market',
        'cut|desc' => 'Biggest price cut', 'buyers|desc' => 'Most buyers', 'last_seen_at|desc' => 'Last seen', 'suburb|asc' => 'Suburb',
    ];
    if (!\App\Services\Prospecting\MicWork\MicWorkList::hasScores()) { unset($sortOptions['score|desc']); }
    if (!isset($sortOptions[$sortNow])) { $sortNow = collect(array_keys($sortOptions))->first(fn ($k) => str_starts_with($k, explode('|', $sortNow)[0] . '|')) ?? array_key_first($sortOptions); }
@endphp

<div class="mi-filter-bar" data-qa="filter-bar" style="margin-bottom: 8px;">
    {{-- Row 1 --}}
    <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px 8px;">
        <form method="GET" action="{{ route('market-intelligence.work') }}" data-qa="filter-form" style="display: contents;"
              onsubmit="(function(f){ var has = ['suburbs[]','type[]','price_min','price_max','beds','show_status','owner_only','sole_mandate_only','claimed'].some(function(n){ return Array.prototype.some.call(f.elements, function(e){ return e.name === n && ((e.type === 'checkbox' && e.checked) || (e.type !== 'checkbox' && e.value !== '')); }); }); if (!has) { var c = document.createElement('input'); c.type = 'hidden'; c.name = 'clear'; c.value = '1'; f.appendChild(c); } })(this)">
            @foreach($hidden as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @foreach($carry as $k => $v)<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endforeach
            @if($view)<input type="hidden" name="view" value="{{ $view }}">@endif
            <input type="search" name="search" value="{{ request('search') }}" placeholder="Search address, complex, erf, agent, agency, ref" data-qa="filter-search" style="{{ $box }} width: 270px;">

            {{-- Suburb: several at once, the agency's working suburbs first --}}
            <div x-data="{ open: false, q: '' }" @click.outside="open = false" style="position: relative;">
                <button type="button" @click="open = !open" data-qa="filter-suburb" style="{{ $box }} cursor: pointer; min-width: 120px; text-align: left;">
                    Suburb{{ count($selSuburbs) ? ' · ' . count($selSuburbs) : '' }} ▾
                </button>
                <div x-show="open" x-cloak style="position: absolute; z-index: 30; top: 34px; left: 0; width: 270px; max-height: 340px; overflow-y: auto; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,.18); padding: 6px;">
                    <input type="text" x-model="q" placeholder="Filter suburbs…" autocomplete="off" style="{{ $box }} width: 100%; margin-bottom: 4px;">
                    @foreach(['working' => 'Your suburbs', 'others' => 'All suburbs'] as $grp => $grpLabel)
                        @if(!empty($suburbOptions[$grp]))
                            <div style="font-size: .625rem; font-weight: 700; text-transform: uppercase; color: var(--text-muted); padding: 5px 2px 3px;">{{ $grpLabel }}</div>
                            @foreach($suburbOptions[$grp] as $o)
                                <label x-show="q === '' || '{{ addslashes(mb_strtolower($o['name'])) }}'.includes(q.toLowerCase())" style="display: flex; align-items: center; gap: 6px; font-size: .8125rem; padding: 2px 2px; cursor: pointer;">
                                    <input type="checkbox" name="suburbs[]" value="{{ $o['id'] }}" @checked(in_array($o['id'], $selSuburbs, true))> <span style="flex: 1;">{{ $o['name'] }}</span><span style="color: var(--text-muted); font-size: .6875rem;">{{ number_format($o['count']) }}</span>
                                </label>
                            @endforeach
                        @endif
                    @endforeach
                    <button type="submit" class="corex-btn text-xs" style="margin-top: 6px; width: 100%;">Apply</button>
                </div>
            </div>

            <select name="type[]" onchange="this.form.requestSubmit()" data-qa="filter-type" style="{{ $box }}">
                <option value="">Type</option>
                @foreach($agg['by_type'] as $row)<option value="{{ $row->property_type }}" @selected(in_array($row->property_type, $selTypes, true))>{{ $row->property_type }} ({{ number_format($row->c ?? 0) }})</option>@endforeach
            </select>
            <input type="number" name="price_min" value="{{ $F['price_min'] ?? '' }}" placeholder="Min price" min="0" step="50000" style="{{ $box }} width: 100px;">
            <input type="number" name="price_max" value="{{ $F['price_max'] ?? '' }}" placeholder="Max price" min="0" step="50000" style="{{ $box }} width: 100px;">
            <select name="beds" onchange="this.form.requestSubmit()" data-qa="filter-beds" style="{{ $box }}">
                <option value="">Beds</option>
                @foreach(range(1, 7) as $b)<option value="{{ $b }}" @selected((string) ($F['beds'] ?? '') === (string) $b)>{{ $b }}+ bed{{ $b > 1 ? 's' : '' }}</option>@endforeach
            </select>
            <button type="submit" class="corex-btn text-xs" style="height: 30px;">Search</button>
        </form>

        <button type="button" x-data x-on:click="$dispatch('mic-more-toggle')" data-qa="more-filters-toggle" class="corex-btn-outline text-xs" style="height: 30px;">
            More filters{{ $moreCount ? ' · ' . $moreCount : '' }} ▾
        </button>
        <a href="{{ route('market-intelligence.work', ['clear' => 1]) }}" data-qa="filter-clear" style="font-size: .8125rem; color: var(--brand-icon); text-decoration: none;">Clear</a>

        <span style="margin-left: auto; display: inline-flex; align-items: center; gap: 8px;">
            <label for="mi-sort-select" style="font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; font-size: 0.625rem; color: var(--text-muted);">Sort</label>
            <select id="mi-sort-select" data-qa="filter-sort" style="{{ $box }}"
                    onchange="(function(s){ var url = new URL(window.location.href); var p = s.value.split('|'); url.searchParams.set('sort', p[0]); url.searchParams.set('dir', p[1] || 'desc'); url.searchParams.delete('page'); window.location.href = url.toString(); })(this)">
                @foreach($sortOptions as $v => $label)<option value="{{ $v }}" @selected($sortNow === $v)>{{ $label }}</option>@endforeach
            </select>
            <span data-qa="layout-toggle" role="group" aria-label="View" style="display: inline-flex; border: 1px solid var(--border); border-radius: 4px; overflow: hidden;">
                @foreach(['adverts' => 'Adverts', 'list' => 'List'] as $lv => $ll)
                    <a href="{{ $urlWith(['view' => $lv]) }}" data-qa="layout-{{ $lv }}" aria-pressed="{{ $layout === $lv ? 'true' : 'false' }}"
                       style="padding: 0 12px; line-height: 28px; font-size: .8125rem; text-decoration: none; {{ $layout === $lv ? 'background: var(--brand-button, #0ea5e9); color: #fff; font-weight: 600;' : 'background: var(--surface-2); color: var(--text-secondary);' }}">{{ $ll }}</a>
                @endforeach
            </span>
        </span>
    </div>

    {{-- Active filters as chips --}}
    @if($chips)
    <div data-qa="filter-chips" style="display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px;">
        @foreach($chips as [$label, $removeUrl])
            <a href="{{ $removeUrl }}" title="Remove this filter" style="display: inline-flex; align-items: center; gap: 4px; padding: 1px 8px; font-size: .75rem; border: 1px solid var(--brand-icon); color: var(--brand-icon); border-radius: 10px; text-decoration: none;">{{ $label }} <span aria-hidden="true">×</span></a>
        @endforeach
    </div>
    @endif

    {{-- Row 2: More filters (collapsed unless one is on) --}}
    <div x-data="{ open: {{ $moreOn ? 'true' : 'false' }} }" @mic-more-toggle.window="open = !open" x-show="open" x-cloak data-qa="more-filters" class="mi-filter-bar-more"
         style="margin-top: 8px; padding: 8px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface);">
        <div style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin-bottom: 6px;">
            @foreach([['show_status', 'all', 'Show under offer / sold', ($F['show_status'] ?? '') === 'all'], ['owner_only', '1', 'Owner advertised', !empty($F['owner_only'])], ['sole_mandate_only', '1', 'Sole mandate', !empty($F['sole_mandate_only'])]] as [$k, $val, $lbl, $on])
                <a href="{{ $fLink([$k => $on ? null : $val]) }}" data-qa="more-{{ $k }}" style="font-size: .8125rem; text-decoration: none; color: {{ $on ? 'var(--brand-icon)' : 'var(--text-secondary)' }};">
                    <span style="display: inline-block; width: 14px; height: 14px; line-height: 12px; text-align: center; border: 1px solid {{ $on ? 'var(--brand-icon)' : 'var(--border)' }}; border-radius: 3px; margin-right: 4px; font-size: .625rem;">{{ $on ? '✓' : '' }}</span>{{ $lbl }}
                </a>
            @endforeach
            <span style="font-size: .75rem; color: var(--text-muted);">Claimed</span>
            @foreach(['any' => 'Any', 'unclaimed' => 'Unclaimed', 'mine' => 'Mine', 'others' => 'Others'] as $cv => $cl)
                @php $cOn = (($F['claimed'] ?? 'any') === $cv); @endphp
                <a href="{{ $fLink(['claimed' => $cv === 'any' ? null : $cv]) }}" data-qa="more-claimed-{{ $cv }}" style="font-size: .8125rem; text-decoration: none; padding: 1px 8px; border-radius: 10px; border: 1px solid {{ $cOn ? 'var(--brand-icon)' : 'var(--border)' }}; color: {{ $cOn ? 'var(--brand-icon)' : 'var(--text-secondary)' }};">{{ $cl }}</a>
            @endforeach
        </div>
        {{-- the buyer-match legend with the P24 / PP / with-address / in-stock ticks, "tune" and "About this page" - unchanged, moved here --}}
        <div id="mic-slot-buyer-legend">
            @include('corex.market-intelligence._buyer-match-legend')
        </div>
        {{-- the old left-column groups (Prospect for buyer, region, demand pockets, price bands, status, source, captured by, date, agency), unchanged, in a grid --}}
        @include('corex.market-intelligence._filter-rail', ['railCompact' => true])
    </div>
</div>
