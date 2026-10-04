<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Landlord Property Activity — {{ $property->buildDisplayAddress() }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#1b2a2c; background:#fff; margin:0; padding:24px; font-size:12px; }
        .print-header { border-bottom:2px solid #1b2a2c; padding-bottom:12px; margin-bottom:18px; }
        h1.report-title { font-size:18px; margin:0 0 2px; }
        h2.section-title { font-size:14px; margin:18px 0 6px; }
        table { width:100%; border-collapse:collapse; margin-bottom:6px; }
        th, td { text-align:left; padding:4px 6px; border-bottom:1px solid #e3ddc9; }
        tfoot td { border-top:2px solid #1b2a2c; font-weight:700; }
    </style>
</head>
<body>

<div class="print-header">
    <h1 class="report-title">Landlord Property Activity Report</h1>
    <div>{{ $property->buildDisplayAddress() }}</div>
    <div style="font-size:11px; color:#5c6c6d;">
        Period: {{ $period['from'] ? $period['from']->format('Y-m-d') : 'inception' }} – {{ $period['to'] ? $period['to']->format('Y-m-d') : 'today' }}
    </div>
</div>

<h2 class="section-title">Faults ({{ $faults->count() }})</h2>
<table>
    <thead><tr><th>Date reported</th><th>Fault</th><th>Status</th><th>Outcome</th></tr></thead>
    <tbody>
        @forelse($faults as $f)
        <tr>
            <td>{{ optional($f->reported_at)->format('Y-m-d') }}</td>
            <td>{{ $f->faultType?->name ?? $f->title }}</td>
            <td>{{ ucwords(str_replace('_', ' ', (string) $f->status)) }}</td>
            <td>{{ $f->outcome ? ucwords(str_replace('_', ' ', $f->outcome)) : '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="4">No faults in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<h2 class="section-title">Work orders ({{ $workOrders->count() }})</h2>
<table>
    <thead><tr><th>Date raised</th><th>Supplier</th><th>Status</th><th>Amount</th></tr></thead>
    <tbody>
        @forelse($workOrders as $w)
        @php($amount = $w->quotes->firstWhere('is_selected', true)?->amount ?? $w->cost_amount)
        <tr>
            <td>{{ optional($w->reported_at)->format('Y-m-d') }}</td>
            <td>{{ $w->supplier?->name ?? '—' }}</td>
            <td>{{ ucwords(str_replace('_', ' ', (string) $w->status)) }}</td>
            <td>{{ $amount !== null ? 'R ' . number_format((float) $amount, 2) : '—' }}</td>
        </tr>
        @empty
        <tr><td colspan="4">No work orders in this period.</td></tr>
        @endforelse
    </tbody>
    <tfoot>
        <tr><td colspan="3">Total</td><td>R {{ number_format((float) $totalWorkOrderAmount, 2) }}</td></tr>
    </tfoot>
</table>

<h2 class="section-title">Inspections ({{ $inspections->count() }})</h2>
<table>
    <thead><tr><th>Scheduled</th><th>Type</th><th>Status</th></tr></thead>
    <tbody>
        @forelse($inspections as $i)
        <tr>
            <td>{{ optional($i->scheduled_for)->format('Y-m-d') }}</td>
            <td>{{ ucfirst(str_replace('_', ' ', $i->type)) }}</td>
            <td>{{ ucwords(str_replace('_', ' ', (string) $i->status)) }}</td>
        </tr>
        @empty
        <tr><td colspan="3">No inspections in this period.</td></tr>
        @endforelse
    </tbody>
</table>

</body>
</html>
