<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $agency?->name }} — Agency Performance &amp; ROI</title>
    @include('performance.agency-report._print-styles', [
        'printTitle'  => 'Agency Performance & ROI — Company',
        'periodLabel' => $report['period']['label'] ?? '',
        'agency'      => $agency,
    ])
</head>
<body>
    @php
        // Mirrors the screen: the same deal-status ticks, the same comparison, the same figures.
        $range = $comparisonMeta['range'] ?? null;
        $rand  = fn (float $v) => 'R ' . number_format($v, 2);
        $trend = function (?array $c, bool $money, ?string $lead = null) use ($range, $rand) {
            if (!$c || !$range) return '';
            if ($c['value'] == 0 && $c['previous'] == 0) return '—';
            $arrow = $c['delta'] > 0 ? '▲ +' : ($c['delta'] < 0 ? '▼ -' : '');
            $abs   = abs($c['delta']);
            $amt   = $money ? $rand($abs) : number_format($abs);
            $pct   = $c['delta_pct'] !== null ? ' (' . ($c['delta_pct'] > 0 ? '+' : '') . $c['delta_pct'] . '%)' : '';
            $prev  = $money ? $rand((float) $c['previous']) : number_format((float) $c['previous']);
            return ($lead !== null ? $lead . ' · ' : '') . $arrow . $amt . $pct . ' · ' . $range . ': ' . $prev;
        };
        $trendClass = fn (?array $c) => !$c || $c['good'] === null ? 'trend-flat' : ($c['good'] ? 'trend-up' : 'trend-down');
        $heroValue = (float) ($report['company']['commission_gross_ex_vat'] ?? 0);
        $heroComp  = $comparison['company']['commission_gross_ex_vat'] ?? null;
    @endphp
    <p class="foot" style="margin:0 0 6px; font-size:11px; color:#444;" data-qa="counting-statuses">
        Counting {{ $statuses->isEverything() ? 'all deals, including Declined' : $statuses->label() . ' deals' }}{{ in_array('declined', $statuses->buckets(), true) ? '' : ' (Declined excluded)' }}.
    </p>
    <p style="font-size:14px; font-weight:600; margin:0 0 4px;" data-qa="hero">
        {{ $report['period']['label'] }}, the agency earned {{ $rand($heroValue) }} in commission.
        @if($heroComp && $range)<br><span class="{{ $trendClass($heroComp) }}" style="font-size:11px; font-weight:400;" data-qa="hero-compare">{{ $trend($heroComp, true, $rand($heroValue)) }}</span>@endif
    </p>

    {{-- Company totals --}}
    <h2 class="section">Company totals</h2>
    <div class="cards">
        @foreach($report['metrics'] as $m)
            @php $cc = $comparison['company'][$m['key']] ?? null; @endphp
            <div class="card">
                @if($m['key'] === 'commission_gross_ex_vat')
                    @php
                        $agentV = (float) ($report['company']['commission_gross_ex_vat'] ?? 0);
                        $coV    = (float) ($report['company']['commission_gross_ex_vat_company'] ?? 0);
                    @endphp
                    <div class="v">{{ $rand($agentV + $coV) }}</div>
                    <div class="k">Agent share {{ $rand($agentV) }}<br>@if($range)<span class="{{ $trendClass($cc) }}">{{ $trend($cc, true) }}</span><br>@endif
                        Company share {{ $rand($coV) }}@if($range)<br><span class="{{ $trendClass($comparison['company']['commission_gross_ex_vat_company'] ?? null) }}">{{ $trend($comparison['company']['commission_gross_ex_vat_company'] ?? null, true) }}</span>@endif</div>
                    <div class="k">{{ $m['label'] }}</div>
                    @if($range)<div class="k {{ $trendClass($comparison['company']['commission_gross_total'] ?? null) }}">Gross: {{ $trend($comparison['company']['commission_gross_total'] ?? null, true) }}</div>@endif
                @else
                    <div class="v">{{ number_format((float) ($report['company'][$m['key']] ?? 0)) }}</div>
                    <div class="k">{{ $m['label'] }}</div>
                    @if($range)<div class="k {{ $trendClass($cc) }}">{{ $trend($cc, false) }}</div>@endif
                @endif
            </div>
        @endforeach
    </div>

    {{-- By branch --}}
    <h2 class="section">By branch</h2>
    <table>
        <thead>
            <tr>
                <th class="l">Branch</th>
                @foreach($report['metrics'] as $m)<th>{{ $m['column_label'] ?? $m['label'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($report['branches'] as $branch)
                <tr>
                    <td class="l">{{ $branch['label'] }}</td>
                    @foreach($report['metrics'] as $m)<td>{{ number_format((float) ($branch['metrics'][$m['key']] ?? 0)) }}</td>@endforeach
                </tr>
            @empty
                <tr><td class="l" colspan="{{ count($report['metrics']) + 1 }}">No branches in scope.</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- By agent --}}
    <h2 class="section">By agent</h2>
    <table>
        <thead>
            <tr>
                <th class="l">Agent</th>
                <th class="l">Branch</th>
                @foreach($report['metrics'] as $m)<th>{{ $m['column_label'] ?? $m['label'] }}</th>@endforeach
            </tr>
        </thead>
        <tbody>
            @forelse($report['agents'] as $agent)
                <tr>
                    <td class="l">{{ $agent['name'] }}</td>
                    <td class="l">{{ $agent['branch_label'] }}</td>
                    @foreach($report['metrics'] as $m)<td>{{ number_format((float) ($agent['metrics'][$m['key']] ?? 0)) }}</td>@endforeach
                </tr>
            @empty
                <tr><td class="l" colspan="{{ count($report['metrics']) + 2 }}">No agents in scope.</td></tr>
            @endforelse
        </tbody>
    </table>

    <p class="foot">Agency Performance &amp; ROI · {{ count($report['metrics']) }} metrics · agent → branch → company · point-in-time branch attribution.</p>
</body>
</html>
