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
    {{-- Company totals --}}
    <h2 class="section">Company totals</h2>
    <div class="cards">
        @foreach($report['metrics'] as $m)
            <div class="card">
                <div class="v">{{ number_format((float) ($report['company'][$m['key']] ?? 0)) }}</div>
                <div class="k">{{ $m['label'] }}</div>
            </div>
        @endforeach
    </div>

    {{-- By branch --}}
    <h2 class="section">By branch</h2>
    <table>
        <thead>
            <tr>
                <th class="l">Branch</th>
                @foreach($report['metrics'] as $m)<th>{{ $m['label'] }}</th>@endforeach
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
                @foreach($report['metrics'] as $m)<th>{{ $m['label'] }}</th>@endforeach
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
