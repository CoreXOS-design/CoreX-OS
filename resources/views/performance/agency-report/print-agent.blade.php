<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $journey['agent']['name'] ?? 'Agent' }} — Performance</title>
    @include('performance.agency-report._print-styles', [
        'printTitle'  => 'Agent Performance — ' . ($journey['agent']['name'] ?? ''),
        'periodLabel' => ($journey['period']['label'] ?? '') . ' (vs ' . ($journey['previous_period']['label'] ?? '') . ')',
        'agency'      => $agency,
    ])
</head>
<body>
    <h2 class="section">{{ $journey['agent']['name'] }} · {{ $journey['agent']['branch_label'] }}</h2>

    <div class="cards">
        @foreach($journey['metrics'] as $m)
            @php
                $delta = $m['delta'];
                $cls = $delta > 0 ? 'trend-up' : ($delta < 0 ? 'trend-down' : 'trend-flat');
                $arrow = $delta > 0 ? '▲' : ($delta < 0 ? '▼' : '–');
            @endphp
            <div class="card">
                <div class="v">{{ number_format((float) $m['value']) }}</div>
                <div class="k">{{ $m['label'] }}</div>
                <div class="k {{ $cls }}">{{ $arrow }} {{ $delta > 0 ? '+' : '' }}{{ number_format((float) $delta) }}
                    <span style="color:#888;">vs {{ number_format((float) $m['previous']) }} prior</span>
                </div>
            </div>
        @endforeach
    </div>

    {{-- Full metric table (print-friendly, sortable N/A on paper). --}}
    <h2 class="section">Metrics — this period vs prior</h2>
    <table>
        <thead>
            <tr><th class="l">Metric</th><th>This period</th><th>Prior period</th><th>Change</th></tr>
        </thead>
        <tbody>
            @foreach($journey['metrics'] as $m)
                @php $cls = $m['delta'] > 0 ? 'trend-up' : ($m['delta'] < 0 ? 'trend-down' : 'trend-flat'); @endphp
                <tr>
                    <td class="l">{{ $m['label'] }}</td>
                    <td>{{ number_format((float) $m['value']) }}</td>
                    <td>{{ number_format((float) $m['previous']) }}</td>
                    <td class="{{ $cls }}">{{ $m['delta'] > 0 ? '+' : '' }}{{ number_format((float) $m['delta']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <p class="foot">AT-366-C agent journey · {{ count($journey['metrics']) }} metrics · current vs prior period.</p>
</body>
</html>
