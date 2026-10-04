<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $agencyName }} — Rental Command Centre</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#1b2a2c; background:#fff; margin:0; padding:24px; font-size:12px; }
        .print-header { display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:2px solid #1b2a2c; padding-bottom:12px; margin-bottom:18px; }
        .print-header .agency { font-size:18px; font-weight:700; }
        .print-header .meta { text-align:right; font-size:11px; color:#5c6c6d; }
        h1.report-title { font-size:18px; margin:0 0 2px; }
        .print-actions { margin-bottom:16px; }
        .print-actions button { font-size:13px; padding:7px 14px; border:1px solid #1b2a2c; background:#1b2a2c; color:#fff; border-radius:6px; cursor:pointer; }
        .tiles { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
        .tile { border:1px solid #d7d2c2; border-radius:6px; padding:8px 12px; min-width:110px; }
        .tile .n { font-size:16px; font-weight:700; }
        .tile .l { font-size:9.5px; text-transform:uppercase; color:#5c6c6d; }
        table { width:100%; border-collapse:collapse; }
        thead { display:table-header-group; }
        th, td { text-align:left; padding:5px 6px; border-bottom:1px solid #e3ddc9; }
        tr { break-inside:avoid; page-break-inside:avoid; }
        @media print {
            body { padding:0; }
            .print-actions { display:none; }
        }
    </style>
</head>
<body>

<div class="print-header">
    <div>
        <div class="agency">{{ $agencyName }}</div>
        <h1 class="report-title">Rental Command Centre</h1>
    </div>
    <div class="meta">
        <div>Showing: {{ ucfirst($scope) }}</div>
        <div>Generated {{ $generatedAt->format('Y-m-d H:i') }}</div>
    </div>
</div>

<div class="print-actions"><button onclick="window.print()">Print</button></div>

<div class="tiles">
    @foreach($tiles as $key => $label)
        <div class="tile"><div class="n">{{ number_format((int) $tileCounts[$key]) }}</div><div class="l">{{ $label }}</div></div>
    @endforeach
</div>

<table>
    <thead>
        <tr>
            <th>Property</th>
            <th>Status</th>
            <th>Tenant(s)</th>
            <th>Lease end</th>
            <th>Open faults</th>
            <th>Open work orders</th>
            <th>Last inspection</th>
            <th>Agent</th>
        </tr>
    </thead>
    <tbody>
        @forelse($properties as $property)
        <tr>
            <td>{{ $property->buildDisplayAddress() }}</td>
            <td>{{ ucwords(str_replace('_', ' ', (string) $property->status)) }}</td>
            <td>{{ $property->active_lease_id ? ($tenantNamesByLeaseId[$property->active_lease_id] ?? 'No tenant linked') : '— vacant —' }}</td>
            <td>{{ $property->active_end_date ? \Illuminate\Support\Carbon::parse($property->active_end_date)->format('Y-m-d') : ($property->active_month_to_month ? 'Month-to-month' : '—') }}</td>
            <td>{{ (int) $property->open_faults_count }}</td>
            <td>{{ (int) $property->open_work_orders_count }}</td>
            <td>{{ $property->last_inspection_at ? \Illuminate\Support\Carbon::parse($property->last_inspection_at)->format('Y-m-d') : 'Never' }}</td>
            <td>{{ $property->agent?->name ?? '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="8">No rental properties match this filter.</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
