{{--
    F.2 — Stats strip.

    Row 1: 5 informational snapshot tiles (Active, Buyer matched, In stock,
            New today, Cross-listed). The "In stock" tile is the only
            interactive tile in Row 1 — clicking it toggles the
            ?include_in_stock=1 audit flag (manager-only).
    Row 2: 5 action preset tiles. Click → ?action_preset=<key>; the active
            preset highlights in info/teal.

    Counts come from $snapshotKpis + $actionPresetCounts (controller-computed).

    Spec: build-f-market-intelligence-redesign-spec.md §8.2.
--}}
@php
    $isManager = auth()->user()?->hasPermission('prospecting_setup.manage') ?? false;
    $kpis = $snapshotKpis ?? ['active'=>0,'buyer_matched'=>0,'in_stock'=>0,'new_today'=>0,'cross_listed'=>0];
    $presets = $actionPresetCounts ?? ['pitch_now_high'=>0,'pitch_now'=>0,'log_outcomes'=>0,'my_claims'=>0,'expiring'=>0];
    $activeActionPreset = $actionPreset ?? null;

    // URL builders preserve all other query params and avoid the page= cursor
    // when the user filters down.
    $urlWithPreset = function (string $key) {
        $params = array_merge(request()->except(['action_preset', 'page']), ['action_preset' => $key]);
        return route('market-intelligence.index', $params);
    };
    $urlClearPreset = route('market-intelligence.index', request()->except(['action_preset', 'page']));

    $urlToggleInStock = function () {
        $params = request()->except(['page']);
        if (request()->boolean('include_in_stock')) {
            unset($params['include_in_stock']);
        } else {
            $params['include_in_stock'] = '1';
        }
        return route('market-intelligence.index', $params);
    };

    $tileBaseStyle = 'background: var(--surface); border: 1px solid var(--border); padding: 12px 14px; border-radius: 6px; min-width: 0;';
    $tileActiveStyle = 'background: color-mix(in srgb, var(--brand-icon) 12%, var(--surface)); border-color: var(--brand-icon); padding: 12px 14px; border-radius: 6px; min-width: 0;';
    $labelStyle = 'font-size: 0.6875rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted); margin-bottom: 4px;';
    $valueStyle = 'font-size: 1.5rem; font-weight: 600; color: var(--text-primary); line-height: 1.1;';
@endphp

<div class="mi-stats-strip" style="padding: 12px 16px; background: var(--surface-2); border-bottom: 1px solid var(--border); display: flex; flex-direction: column; gap: 10px;">

    {{-- Row 1 — informational --}}
    <div class="mi-stats-row" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; overflow-x: auto;">
        <div style="{{ $tileBaseStyle }}">
            <div style="{{ $labelStyle }}">Active</div>
            <div style="{{ $valueStyle }}">{{ number_format($kpis['active']) }}</div>
        </div>
        <div style="{{ $tileBaseStyle }}">
            <div style="{{ $labelStyle }}">Buyer matched</div>
            <div style="{{ $valueStyle }}; color: var(--ds-green, #10b981);">{{ number_format($kpis['buyer_matched']) }}</div>
        </div>
        @if($isManager)
        <a href="{{ $urlToggleInStock() }}"
           style="text-decoration: none; {{ request()->boolean('include_in_stock') ? $tileActiveStyle : $tileBaseStyle }} cursor: pointer;"
           title="Click to {{ request()->boolean('include_in_stock') ? 'hide' : 'include' }} in-stock listings (audit mode)">
            <div style="{{ $labelStyle }}">In stock {{ request()->boolean('include_in_stock') ? '(audit on)' : '' }}</div>
            <div style="{{ $valueStyle }}">{{ number_format($kpis['in_stock']) }}</div>
        </a>
        @else
        <div style="{{ $tileBaseStyle }}">
            <div style="{{ $labelStyle }}">In stock</div>
            <div style="{{ $valueStyle }}">{{ number_format($kpis['in_stock']) }}</div>
        </div>
        @endif
        <div style="{{ $tileBaseStyle }}">
            <div style="{{ $labelStyle }}">New today</div>
            <div style="{{ $valueStyle }}">{{ number_format($kpis['new_today']) }}</div>
        </div>
        <div style="{{ $tileBaseStyle }}">
            <div style="{{ $labelStyle }}">Cross-listed</div>
            <div style="{{ $valueStyle }}; color: var(--ds-amber, #f59e0b);">{{ number_format($kpis['cross_listed']) }}</div>
        </div>
    </div>

    {{-- Row 2 — action presets (clickable filters) --}}
    @php
        // Each tile: [key, label, count, accent_color_var]
        $tiles = [
            ['pitch_now_high', 'Pitch now · high', $presets['pitch_now_high'], 'var(--ds-teal, #10b981)'],
            ['pitch_now',      'Pitch now',         $presets['pitch_now'],      'var(--ds-teal, #10b981)'],
            ['log_outcomes',   'Log outcomes',      $presets['log_outcomes'],   'var(--ds-amber, #f59e0b)'],
            ['my_claims',      'My claims',         $presets['my_claims'],      'var(--brand-icon, #0ea5e9)'],
            ['expiring',       'Expiring',          $presets['expiring'],       'var(--ds-crimson, #dc2626)'],
        ];
    @endphp
    <div class="mi-stats-row" style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; overflow-x: auto;">
        @foreach($tiles as [$key, $label, $count, $accent])
            @php
                $isActive = $activeActionPreset === $key;
                $href = $isActive ? $urlClearPreset : $urlWithPreset($key);
            @endphp
            <a href="{{ $href }}"
               style="text-decoration: none; {{ $isActive ? $tileActiveStyle : $tileBaseStyle }} display: block; cursor: pointer;"
               title="{{ $isActive ? 'Click to clear filter' : 'Click to filter listings to this preset' }}">
                <div style="{{ $labelStyle }}; color: {{ $isActive ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ $label }}</div>
                <div style="{{ $valueStyle }}; color: {{ $count > 0 ? $accent : 'var(--text-muted)' }};">{{ number_format($count) }}</div>
            </a>
        @endforeach
    </div>
</div>
