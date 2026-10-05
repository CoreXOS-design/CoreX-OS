<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $reports[$reportKey] ?? 'Rental Report' }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#1b2a2c; background:#fff; margin:0; padding:24px; font-size:12px; }
        .print-header { display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:2px solid #1b2a2c; padding-bottom:12px; margin-bottom:18px; }
        .print-header .meta { text-align:right; font-size:11px; color:#5c6c6d; }
        h1.report-title { font-size:18px; margin:0 0 2px; }
        .print-actions { margin-bottom:16px; }
        .print-actions button { font-size:13px; padding:7px 14px; border:1px solid #1b2a2c; background:#1b2a2c; color:#fff; border-radius:6px; cursor:pointer; }
        table { width:100%; border-collapse:collapse; }
        thead { display:table-header-group; }
        th, td { text-align:left; padding:5px 6px; border-bottom:1px solid #e3ddc9; }
        tr.group-row td { background:#f3f1e8; font-weight:600; }
        tfoot td { border-top:2px solid #1b2a2c; font-weight:700; }
        tr { break-inside:avoid; page-break-inside:avoid; }
        @media print { body { padding:0; } .print-actions { display:none; } }
    </style>
</head>
<body>

<div class="print-header">
    <div>
        <h1 class="report-title">{{ $reports[$reportKey] ?? 'Rental Report' }}</h1>
    </div>
    <div class="meta">
        <div>Showing: {{ ucfirst($params['scope'] ?? 'own') }}</div>
        @if(!empty($params['period']))<div>Period: {{ ucfirst(str_replace('_', ' ', $params['period'])) }}@if(($params['date_from'] ?? null) || ($params['date_to'] ?? null)) ({{ $params['date_from'] ?? '…' }} – {{ $params['date_to'] ?? '…' }})@endif</div>@endif
        @if(!empty($result['selectedBuckets']))<div>Statuses: {{ implode(', ', array_map(fn($k) => is_array($result['buckets'][$k] ?? null) ? $result['buckets'][$k]['label'] : ($result['buckets'][$k] ?? $k), $result['selectedBuckets'])) }}</div>@endif
        <div>Generated {{ $generatedAt->format('Y-m-d H:i') }}</div>
    </div>
</div>

@if(!($forPdf ?? false))
<div class="print-actions"><button onclick="window.print()">Print</button></div>
@endif

<table>
    <thead>
        <tr>
            @foreach($result['columns'] as $colKey => $colLabel)
                <th>{{ $colLabel }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @php($prevGroup = null)
        @forelse($result['rows'] as $row)
            @if(!empty($row['_group']) && $row['_group'] !== $prevGroup)
            <tr class="group-row"><td colspan="{{ count($result['columns']) }}">{{ $row['_group'] }} ({{ $result['groups'][$row['_group']] ?? '' }})</td></tr>
            @endif
            @php($prevGroup = $row['_group'] ?? null)
            <tr>
                @foreach($result['columns'] as $colKey => $colLabel)
                    <td>{{ (is_numeric($row[$colKey] ?? null) && (str_contains($colKey, 'amount') || $colKey === 'rent' || str_starts_with($colKey, 'total_'))) ? 'R ' . number_format((float) $row[$colKey], 2) : ($row[$colKey] ?? '—') }}</td>
                @endforeach
            </tr>
        @empty
            <tr><td colspan="{{ count($result['columns']) }}">No {{ strtolower($reports[$reportKey] ?? 'results') }} in this period for this scope.</td></tr>
        @endforelse
    </tbody>
    @if($result['count'] > 0 && !empty($result['sumKeys']))
    <tfoot>
        <tr>
            @foreach($result['columns'] as $colKey => $colLabel)
                @if($loop->first)
                    <td>Total ({{ $result['count'] }})</td>
                @elseif(in_array($colKey, $result['sumKeys'], true))
                    @php($sum = $result['rows']->sum(fn($r) => (float) ($r[$colKey] ?? 0)))
                    <td>{{ (str_contains($colKey, 'amount') || $colKey === 'rent' || str_starts_with($colKey, 'total_')) ? 'R ' . number_format($sum, 2) : number_format($sum, 0) }}</td>
                @else
                    <td></td>
                @endif
            @endforeach
        </tr>
    </tfoot>
    @endif
</table>

</body>
</html>
