<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Property History — {{ $property->buildDisplayAddress() }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#1b2a2c; background:#fff; margin:0; padding:24px; font-size:12px; }
        .print-header { border-bottom:2px solid #1b2a2c; padding-bottom:12px; margin-bottom:18px; }
        h1.report-title { font-size:18px; margin:0 0 2px; }
        .print-actions { margin-bottom:16px; }
        .print-actions button { font-size:13px; padding:7px 14px; border:1px solid #1b2a2c; background:#1b2a2c; color:#fff; border-radius:6px; cursor:pointer; }
        .lease-block { margin-bottom:16px; break-inside:avoid; }
        .lease-head { font-weight:700; background:#f3f1e8; padding:5px 6px; }
        table { width:100%; border-collapse:collapse; margin-bottom:6px; }
        th, td { text-align:left; padding:4px 6px; border-bottom:1px solid #e3ddc9; }
        @media print { body { padding:0; } .print-actions { display:none; } }
    </style>
</head>
<body>

<div class="print-header">
    <h1 class="report-title">Property History — {{ $property->buildDisplayAddress() }}</h1>
</div>

@if(!($forPdf ?? false))
<div class="print-actions"><button onclick="window.print()">Print</button></div>
@endif

@forelse($timeline as $block)
<div class="lease-block">
    <div class="lease-head">{{ $block['group'] }} — Tenant: {{ $block['tenant'] }} @if($block['rent']) · Rent: R {{ number_format((float) $block['rent'], 2) }}@endif</div>
    <table>
        <tbody>
            @forelse($block['events'] as $event)
            <tr>
                <td style="width:100px;">{{ $event['date'] ? \Illuminate\Support\Carbon::parse($event['date'])->format('Y-m-d') : '—' }}</td>
                <td style="width:100px;">{{ $event['type'] }}</td>
                <td>{{ $event['description'] }}</td>
            </tr>
            @empty
            <tr><td>Nothing recorded.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@empty
<p>No leases or rental activity on record for this property.</p>
@endforelse

</body>
</html>
