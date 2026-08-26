<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $branding['name'] }} — Suburb Report — {{ $data['suburb']['name'] ?? '' }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#1b2a2c; background:#f5f1e6; margin:0; padding:24px; font-size:13px; }
        .print-header { display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:2px solid #1b2a2c; padding-bottom:12px; margin-bottom:18px; }
        .print-header .agency { font-size:18px; font-weight:700; }
        .print-header .meta { text-align:right; font-size:11px; color:#5c6c6d; }
        .print-header .logo { max-height:48px; max-width:180px; object-fit:contain; }
        h1.report-title { font-size:20px; margin:0 0 2px; }
        .print-actions { margin-bottom:16px; }
        .print-actions button { font-size:13px; padding:7px 14px; border:1px solid #1b2a2c; background:#1b2a2c; color:#fff; border-radius:6px; cursor:pointer; }
        .foot { margin-top:24px; font-size:9.5px; color:#8a9697; border-top:1px solid #e3ddc9; padding-top:10px; }
        table { width:100%; border-collapse:collapse; }
        thead { display:table-header-group; }
        tr { break-inside:avoid; page-break-inside:avoid; }
        @media print {
            body { padding:0; background:#fff; }
            .print-actions { display:none; }
        }
    </style>
</head>
<body>

<div class="print-header">
    <div>
        @if($logoData)
            <img src="{{ $logoData }}" alt="{{ $branding['name'] }}" class="logo">
        @else
            <div class="agency">{{ $branding['name'] }}</div>
        @endif
        <h1 class="report-title">{{ $data['suburb']['name'] ?? ('#' . $suburb->id) }} — Suburb Report</h1>
    </div>
    <div class="meta">
        <div><strong>{{ $branding['name'] }}</strong></div>
        @if($data['suburb']['municipality_confirmed'])
        <div>{{ $data['suburb']['municipality'] }}</div>
        @endif
        <div>Generated {{ $generatedAt->format('d M Y, H:i') }}</div>
    </div>
</div>

<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save PDF</button>
</div>

@include('corex.market-intelligence._suburb-report-body')

<div class="foot">{{ $branding['name'] }} — Suburb Report for {{ $data['suburb']['name'] ?? '' }}, generated {{ $generatedAt->format('d M Y, H:i') }}. Figures reflect {{ $branding['name'] }}'s own records and any market reports on file as at generation time.</div>

</body>
</html>
