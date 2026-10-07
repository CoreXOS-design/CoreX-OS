<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $branding['name'] }} — Lead Response — {{ $scopeLabel }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#111; background:#fff; margin:0; padding:24px; font-size:12px; }
        .print-header { display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:2px solid #111; padding-bottom:12px; margin-bottom:16px; }
        .print-header .agency { font-size:18px; font-weight:700; }
        .print-header .meta { text-align:right; font-size:11px; color:#555; }
        h1.report-title { font-size:15px; margin:0 0 2px; }
        h2.section { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#666; margin:18px 0 6px; }
        table { width:100%; border-collapse:collapse; font-size:11px; margin-bottom:12px; }
        th, td { padding:5px 8px; border-bottom:1px solid #ddd; text-align:right; }
        th { color:#555; border-bottom:1px solid #111; white-space:nowrap; }
        th.l, td.l { text-align:left; }
        .cards { display:grid; grid-template-columns:repeat(6, 1fr); gap:10px; margin-bottom:8px; }
        .card { border:1px solid #ccc; border-radius:6px; padding:10px; }
        .card .v { font-size:18px; font-weight:700; }
        .card .k { font-size:10px; color:#666; }
        .caveat { font-size:10px; color:#92400e; background:#fef3c7; border:1px solid #f5d99a; border-radius:5px; padding:8px 10px; margin:8px 0 12px; }
        .foot { margin-top:20px; font-size:9px; color:#888; }
        .print-actions { margin-bottom:14px; }
        .print-actions button { font-size:12px; padding:6px 12px; border:1px solid #111; background:#111; color:#fff; border-radius:5px; cursor:pointer; }
        @media print { body { padding:0; } .print-actions { display:none; } thead { display:table-header-group; } tr, .card { break-inside:avoid; } }
    </style>
</head>
<body>
@php
    $lrSvc = app(\App\Services\LeadResponse\LeadResponseService::class);
    $lrc = $leadResponse['company'];
    $fmtMin = fn ($v) => $v === null ? '—' : $lrSvc->formatMinutes((int) $v);
@endphp
<div class="print-actions"><button type="button" onclick="window.print()">Print</button></div>
<div class="print-header">
    <div>
        <div class="agency">{{ $branding['name'] }}</div>
        <h1 class="report-title">Lead Response — {{ $scopeLabel }}</h1>
    </div>
    <div class="meta">{{ $periodLabel }}<br>Generated {{ $generatedAt->format('Y-m-d H:i') }}</div>
</div>
<p class="caveat">Target: first contact within {{ $leadResponse['target'] }} min, counting {{ $lrSvc->hoursSummary($leadResponse['hours']) }}. Contact = the "Contacted" action, a message sent, a link shared, or feedback on an appointment; a note alone does not count.@if(($lrc['not_measured'] ?? 0) > 0) {{ number_format($lrc['not_measured']) }} earlier enquiries (before response tracking began) are not measured.@endif</p>
<div class="cards">
    @foreach([['Leads received', $lrc['received']], ['Responded in target', $lrc['in_target']], ['Responded late', $lrc['late']], ['Not yet contacted', $lrc['waiting']], ['Average response', $fmtMin($lrc['avg'])], ['Median response', $fmtMin($lrc['median'])]] as [$label, $val])
        <div class="card"><div class="v">{{ is_int($val) ? number_format($val) : $val }}</div><div class="k">{{ $label }}</div></div>
    @endforeach
</div>
<h2 class="section">Lead response by agent</h2>
<table>
    <thead><tr><th class="l">Agent</th><th>Received</th><th>In target</th><th>Late</th><th>Not yet</th><th>Average</th><th>Median</th></tr></thead>
    <tbody>
        @forelse(collect($agentRows)->filter(fn ($a) => ($leadResponse['agents'][(int) $a['user_id']]['received'] ?? 0) > 0) as $a)
            @php $s = $leadResponse['agents'][(int) $a['user_id']]; @endphp
            <tr><td class="l">{{ $a['name'] }}</td><td>{{ $s['received'] }}</td><td>{{ $s['in_target'] }}</td><td>{{ $s['late'] }}</td><td>{{ $s['waiting'] }}</td><td>{{ $fmtMin($s['avg']) }}</td><td>{{ $fmtMin($s['median']) }}</td></tr>
        @empty
            <tr><td colspan="7" class="l">No measured enquiries for these agents in this period.</td></tr>
        @endforelse
    </tbody>
</table>
<h2 class="section">Lead response by source</h2>
<table>
    <thead><tr><th class="l">Source</th><th>Received</th><th>In target</th><th>Late</th><th>Not yet</th><th>Average</th><th>Median</th></tr></thead>
    <tbody>
        @forelse(collect($leadResponse['sources'])->filter(fn ($s) => $s['received'] > 0) as $s)
            <tr><td class="l">{{ $s['label'] }}</td><td>{{ $s['received'] }}</td><td>{{ $s['in_target'] }}</td><td>{{ $s['late'] }}</td><td>{{ $s['waiting'] }}</td><td>{{ $fmtMin($s['avg']) }}</td><td>{{ $fmtMin($s['median']) }}</td></tr>
        @empty
            <tr><td colspan="7" class="l">No measured enquiries in this period.</td></tr>
        @endforelse
    </tbody>
</table>
<p class="foot">Summary only — the lists of leads behind each figure are on screen.</p>
</body>
</html>
