{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.6 — Agency share (competitive landscape) in one suburb.
    Top 5 + Others. Self-agency row highlighted. Click an agency name →
    Work mode filtered to ?agency_name=X. Spec: §9.5.
--}}
@php
    $c = $comp ?? ['suburb' => null, 'total_listings' => 0, 'agencies' => [], 'data_available' => false];
    $maxPct = max(1, ...array_map(fn ($a) => $a['percentage'], $c['agencies'] ?: [['percentage' => 1]]));
@endphp

<div class="mi-card">
    <div class="mi-card-title">Agency share{{ $c['suburb'] ? ' · ' . $c['suburb'] : '' }}</div>
    <div class="mi-card-subtitle">share of active canvass-pool listings</div>

    @if(! $c['data_available'])
        <div style="padding: 16px; text-align: center; color: var(--text-muted); font-size: 0.8125rem;">
            No active listings in this suburb to rank.
        </div>
    @else
    <div style="display: flex; flex-direction: column; gap: 6px;">
        @foreach($c['agencies'] as $row)
        @php
            $isSelf = (bool) ($row['is_self'] ?? false);
            $isOthers = $row['name'] === 'Others';
            $barColor = $isSelf
                ? 'var(--brand-icon, #0ea5e9)'
                : ($isOthers ? 'var(--text-muted)' : 'var(--portal-cma, #7c3aed)');
            $rowBg = $isSelf ? 'color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, var(--surface))' : 'var(--surface)';
            $widthPct = max(2, ($row['percentage'] / $maxPct) * 100);
            $href = (!$isOthers && !empty($row['name']))
                ? route('market-intelligence.work', ['agency_name' => $row['name'], 'mode' => 'work'])
                : null;
        @endphp
        <div style="display: grid; grid-template-columns: 1fr 200px auto auto; gap: 8px; align-items: center;
                    padding: 6px 8px; background: {{ $rowBg }}; border: 1px solid var(--border); border-radius: 4px;">
            @if($href)
                <a href="{{ $href }}"
                   style="font-size: 0.75rem; font-weight: {{ $isSelf ? 700 : 500 }}; color: {{ $isSelf ? 'var(--brand-icon, #0ea5e9)' : 'var(--text-primary)' }};
                          text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;"
                   title="Filter Work mode to listings by {{ $row['name'] }}">
                    {{ $row['name'] }}@if($isSelf) <span style="font-size: 0.625rem; opacity: 0.7;">(you)</span>@endif
                </a>
            @else
                <span style="font-size: 0.75rem; font-weight: 500; color: var(--text-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                    {{ $row['name'] }}
                </span>
            @endif
            <div style="height: 12px; background: var(--surface-2); border-radius: 2px; overflow: hidden;">
                <div style="height: 100%; width: {{ $widthPct }}%; background: {{ $barColor }};"></div>
            </div>
            <span style="font-size: 0.6875rem; color: var(--text-muted); font-variant-numeric: tabular-nums; min-width: 22px; text-align: right;">
                {{ $row['count'] }}
            </span>
            <span style="font-size: 0.75rem; font-weight: 600; color: var(--text-primary); font-variant-numeric: tabular-nums; min-width: 44px; text-align: right;">
                {{ $row['percentage'] }}%
            </span>
        </div>
        @endforeach
    </div>
    <div style="margin-top: 8px; font-size: 0.6875rem; color: var(--text-muted); text-align: right;">
        {{ number_format($c['total_listings']) }} active listings in {{ $c['suburb'] }}
    </div>
    @endif
</div>
