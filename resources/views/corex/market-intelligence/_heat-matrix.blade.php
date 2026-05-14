{{--
    F.6 — Demand-vs-supply heat matrix.

    Suburb rows × bedroom columns (1, 2, 3, 4, 5+). Each cell:
      ratio < 0.5  → cold (neutral)
      0.5 – 1.5    → warm (amber tint)
      ≥ 1.5        → hot  (green tint)
      no data      → "—"
    Click a non-empty cell → Work mode pre-filtered to that suburb + bedrooms.

    Spec: §9.2.
--}}
@php
    $m = $matrix ?? ['rows' => [], 'top_suburbs' => [], 'more_suburbs' => 0];
    $beds = [1, 2, 3, 4, 5];

    $cellStyle = function (array $cell): string {
        $base = 'display: block; padding: 6px 4px; text-align: center; font-size: 0.75rem; font-weight: 600; border: 1px solid var(--border); border-radius: 4px; text-decoration: none; min-height: 32px;';
        $tier = $cell['tier'];
        if ($tier === 'hot') {
            return $base . 'background: color-mix(in srgb, var(--ds-green, #10b981) 22%, transparent); color: var(--ds-green, #10b981); border-color: color-mix(in srgb, var(--ds-green, #10b981) 40%, transparent); cursor: pointer;';
        }
        if ($tier === 'warm') {
            return $base . 'background: color-mix(in srgb, var(--ds-amber, #f59e0b) 18%, transparent); color: var(--ds-amber, #f59e0b); border-color: color-mix(in srgb, var(--ds-amber, #f59e0b) 35%, transparent); cursor: pointer;';
        }
        if ($tier === 'cold') {
            return $base . 'background: var(--surface-2); color: var(--text-secondary); cursor: pointer;';
        }
        return $base . 'background: var(--surface); color: var(--text-muted); border-style: dashed; cursor: default;';
    };

    $cellUrl = function (string $suburb, int $bedrooms): string {
        return route('market-intelligence.index', [
            'suburb'         => $suburb,
            'bedrooms_exact' => $bedrooms,
            'mode'           => 'work',
        ]);
    };

    $cellLabel = function (array $cell): string {
        if ($cell['tier'] === 'empty') return '—';
        if ($cell['ratio'] === INF || is_infinite($cell['ratio'] ?? 0)) {
            return $cell['demand'] . 'b';
        }
        $ratio = $cell['ratio'] !== null ? round($cell['ratio'], 1) : 0;
        return $ratio > 0 ? $ratio . '×' : '—';
    };
@endphp

<div class="mi-card">
    <div style="display: flex; align-items: baseline; justify-content: space-between; gap: 12px;">
        <div>
            <div class="mi-card-title" style="margin-bottom: 0;">Demand vs supply</div>
            <div class="mi-card-subtitle">strong-tier buyers per active listing, by suburb × bedrooms</div>
        </div>
        {{-- Legend --}}
        <div style="display: flex; align-items: center; gap: 8px; font-size: 0.625rem; color: var(--text-muted);">
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="width: 10px; height: 10px; border-radius: 2px; background: color-mix(in srgb, var(--ds-green, #10b981) 30%, transparent); display: inline-block;"></span> Hot ≥1.5×
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="width: 10px; height: 10px; border-radius: 2px; background: color-mix(in srgb, var(--ds-amber, #f59e0b) 25%, transparent); display: inline-block;"></span> Warm
            </span>
            <span style="display: inline-flex; align-items: center; gap: 4px;">
                <span style="width: 10px; height: 10px; border-radius: 2px; background: var(--surface-2); display: inline-block; border: 1px solid var(--border);"></span> Cold
            </span>
        </div>
    </div>

    @if(empty($m['rows']))
        <div style="padding: 24px; text-align: center; color: var(--text-muted); font-size: 0.875rem;">
            Not enough data to build a matrix — capture more listings or add buyer wishlists.
        </div>
    @else
    <div style="overflow-x: auto;">
        <table style="width: 100%; border-collapse: separate; border-spacing: 4px; font-size: 0.75rem; min-width: 500px;">
            <thead>
                <tr>
                    <th style="text-align: left; padding: 4px 6px; font-weight: 600; color: var(--text-muted); font-size: 0.6875rem;">Suburb</th>
                    @foreach($beds as $b)
                    <th style="text-align: center; padding: 4px; font-weight: 600; color: var(--text-muted); font-size: 0.6875rem; width: 64px;">
                        {{ $b === 5 ? '5+' : $b }} bed
                    </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($m['rows'] as $row)
                <tr>
                    <td style="padding: 4px 6px; font-weight: 600; color: var(--text-primary); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 160px;">
                        {{ $row['suburb'] }}
                    </td>
                    @foreach($row['cells'] as $cell)
                    <td>
                        @if($cell['tier'] === 'empty')
                            <span style="{{ $cellStyle($cell) }}" title="No supply or demand">{{ $cellLabel($cell) }}</span>
                        @else
                            <a href="{{ $cellUrl($row['suburb'], $cell['bedrooms']) }}"
                               style="{{ $cellStyle($cell) }}"
                               title="{{ $cell['demand'] }} strong buyer{{ $cell['demand'] === 1 ? '' : 's' }} · {{ $cell['supply'] }} listing{{ $cell['supply'] === 1 ? '' : 's' }} → open in Work mode">
                                {{ $cellLabel($cell) }}
                            </a>
                        @endif
                    </td>
                    @endforeach
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    @if($m['more_suburbs'] > 0)
    <div style="margin-top: 8px; font-size: 0.6875rem; color: var(--text-muted); text-align: right;">
        + {{ $m['more_suburbs'] }} more suburb{{ $m['more_suburbs'] === 1 ? '' : 's' }} not shown
    </div>
    @endif
    @endif
</div>
