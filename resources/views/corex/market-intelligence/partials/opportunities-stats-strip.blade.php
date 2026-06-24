{{--
    MIC Phase D4 — Opportunities tab stats strip (5 tiles per spec §5.4.2).
    Each tile clickable; clicking applies the corresponding filter chip.
    Mirrors the visual language of the Work tab's _stats-strip partial.
--}}
@php
    $base = $tileBase ?? 'background: var(--surface, #fff); border: 1px solid var(--border, rgba(0,0,0,0.07)); padding: 8px 10px; border-radius: 6px; min-width: 0;';
    $active = 'background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, var(--surface, #fff)); border: 1px solid var(--brand-icon, #0ea5e9); padding: 8px 10px; border-radius: 6px; min-width: 0;';
    $labelStyle = 'font-size: 0.625rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-muted, #9ca3af); margin-bottom: 2px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;';
    $valueStyle = 'font-size: 1.0625rem; font-weight: 600; color: var(--text-primary, #111827); line-height: 1.1;';

    $urlWithFilter = function (?string $filter) {
        $params = request()->except(['filter', 'page']);
        if ($filter !== null) $params['filter'] = $filter;
        return route('market-intelligence.opportunities', $params);
    };

    $tiles = [
        ['label' => 'Total tracked',     'value' => $stats['total'],             'filter' => null,                'accent' => 'var(--text-primary)',     'tip' => 'Every property CoreX has intelligence on.'],
        ['label' => 'Matching buyers',   'value' => $stats['matching_buyers'],   'filter' => null,                'accent' => 'var(--ds-green, #10b981)','tip' => 'Tracked properties with at least one strong-tier buyer match (score ≥ 80).'],
        ['label' => 'Unclaimed',         'value' => $stats['unclaimed'],         'filter' => null,                'accent' => 'var(--brand-icon, #0ea5e9)','tip' => 'Tracked properties with no active prospecting claim.'],
        ['label' => 'With address',      'value' => $stats['with_address'],      'filter' => 'with_address',      'accent' => 'var(--brand-button)',     'tip' => 'Tracked properties whose primary address has a street name. Click to filter.'],
        ['label' => 'In stock',          'value' => $stats['promoted_to_stock'], 'filter' => 'company_stock',     'accent' => 'var(--ds-amber, #f59e0b)','tip' => 'Promoted to agency stock with audit chain preserved. Click to filter.'],
    ];

    $activeFilter = $activeFilter ?? 'all';
@endphp

<div class="mic-opp-stats" data-tour="mic-opportunities-stats"
     style="margin-bottom: 12px; padding: 4px;
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 8px;">
    @foreach($tiles as $tile)
        @php $isActive = $tile['filter'] !== null && $activeFilter === $tile['filter']; @endphp
        @if($tile['filter'] !== null)
            <a href="{{ $urlWithFilter($tile['filter']) }}"
               style="text-decoration: none; {{ $isActive ? $active : $base }} display: block;"
               title="{{ $tile['tip'] }}{{ $isActive ? ' Currently active — click another tile to switch.' : '' }}">
                <div style="{{ $labelStyle }}; color: {{ $isActive ? 'var(--brand-icon)' : 'var(--text-muted)' }};">{{ $tile['label'] }}</div>
                <div style="{{ $valueStyle }}; color: {{ $tile['value'] > 0 ? $tile['accent'] : 'var(--text-muted)' }};">{{ number_format($tile['value']) }}</div>
            </a>
        @else
            <div style="{{ $base }}" title="{{ $tile['tip'] }}">
                <div style="{{ $labelStyle }}">{{ $tile['label'] }}</div>
                <div style="{{ $valueStyle }}; color: {{ $tile['value'] > 0 ? $tile['accent'] : 'var(--text-muted)' }};">{{ number_format($tile['value']) }}</div>
            </div>
        @endif
    @endforeach
</div>
