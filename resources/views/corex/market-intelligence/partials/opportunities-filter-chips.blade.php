{{--
    MIC Phase D4 — Opportunities tab primary filter chips (spec §5.4.3).
--}}
@php
    $chips = [
        'all'               => 'All',
        'with_address'      => 'With address',
        'without_address'   => 'Without address',
        'company_stock'     => 'Company stock',
        'recently_enriched' => 'Recently enriched',
    ];
    $urlFor = function (string $key) {
        $params = request()->except(['filter', 'page']);
        if ($key !== 'all') $params['filter'] = $key;
        return route('market-intelligence.opportunities', $params);
    };
    $activeFilter = $activeFilter ?? 'all';
@endphp

<div class="mic-opp-chips" data-tour="mic-opportunities-chips"
     style="display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px;">
    @foreach($chips as $key => $label)
        @php $isActive = $activeFilter === $key; @endphp
        <a href="{{ $urlFor($key) }}"
           style="text-decoration: none; padding: 6px 12px; border-radius: 999px;
                  font-size: 0.75rem; font-weight: 500;
                  {{ $isActive
                      ? 'background: var(--brand-button); color: #fff; border: 1px solid var(--brand-button);'
                      : 'background: var(--surface); color: var(--text-secondary); border: 1px solid var(--border);' }}">
            {{ $label }}
        </a>
    @endforeach
</div>
