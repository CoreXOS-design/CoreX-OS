<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $branding['name'] }} — Buyers Report — {{ $scopeLabel }}</title>
    <style>
        :root { color-scheme: light; }
        * { box-sizing: border-box; }
        body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color:#111; background:#fff; margin:0; padding:24px; font-size:12px; }
        .print-header { display:flex; align-items:center; justify-content:space-between; gap:16px; border-bottom:2px solid #111; padding-bottom:12px; margin-bottom:16px; }
        .print-header .agency { font-size:18px; font-weight:700; }
        .print-header .meta { text-align:right; font-size:11px; color:#555; }
        .print-header .logo { max-height:48px; max-width:180px; object-fit:contain; }
        h1.report-title { font-size:15px; margin:0 0 2px; }
        h2.section { font-size:11px; text-transform:uppercase; letter-spacing:.08em; color:#666; margin:18px 0 6px; }
        table { width:100%; border-collapse:collapse; font-size:11px; }
        th, td { padding:5px 8px; border-bottom:1px solid #ddd; }
        th { text-align:right; color:#555; border-bottom:1px solid #111; white-space:nowrap; }
        th.l, td.l { text-align:left; }
        .cards { display:grid; grid-template-columns:repeat(4, 1fr); gap:10px; margin-bottom:8px; }
        .card { border:1px solid #ccc; border-radius:6px; padding:10px; }
        .card .v { font-size:18px; font-weight:700; }
        .card .k { font-size:10px; color:#666; }
        .card .d { font-size:9px; margin-top:2px; }
        .up { color:#15803d; } .down { color:#b91c1c; } .flat { color:#666; }
        .note { font-size:10px; color:#666; margin:4px 0 10px; }
        .caveat { font-size:10px; color:#92400e; background:#fef3c7; border:1px solid #f5d99a; border-radius:5px; padding:8px 10px; margin:8px 0 12px; }
        .list-item { padding:5px 8px; border-bottom:1px solid #eee; font-size:11px; }
        .list-item .who { color:#666; }
        .foot { margin-top:20px; font-size:9px; color:#888; }
        .print-actions { margin-bottom:14px; }
        .print-actions button { font-size:12px; padding:6px 12px; border:1px solid #111; background:#111; color:#fff; border-radius:5px; cursor:pointer; margin-right:8px; }
        @media print {
            body { padding:0; }
            .print-actions { display:none; }
            thead { display:table-header-group; }
            tr { break-inside:avoid; }
            .card { break-inside:avoid; }
            h2.section { break-after:avoid; }
        }
    </style>
</head>
<body>
@php
    $m = $report['company'];
    $money = fn ($v) => 'R ' . number_format((float) $v, 0);
    $stateLabel = fn ($s) => match ($s) { 'warm' => 'Warm', 'new' => 'New', 'cold' => 'Cold', 'lost' => 'Lost', 'won' => 'Won', default => ucfirst((string) $s) };
    // Mirrors x-performance-delta's own rendering rules exactly: arrow from
    // the raw delta sign, colour from the metric's declared good/bad
    // (never colour from sign alone), same PeriodComparison::compute() shape.
    $renderDelta = function (?array $c, string $phrase, bool $isMoney = false) use ($money) {
        if (!$c || ($c['value'] == 0 && $c['previous'] == 0)) return null;
        $up = $c['delta'] > 0; $down = $c['delta'] < 0;
        $cls = $c['good'] === null ? 'flat' : ($c['good'] ? 'up' : 'down');
        $abs = abs($c['delta']);
        $amount = $isMoney ? $money($abs) : number_format($abs);
        $pct = $c['delta_pct'] !== null ? ' (' . ($c['delta_pct'] > 0 ? '+' : '') . $c['delta_pct'] . '%)' : '';
        return ['cls' => $cls, 'text' => ($up ? '▲+' : ($down ? '▼-' : '')) . $amount . $pct . ' ' . $phrase];
    };
@endphp

<div class="print-header">
    <div>
        @if($logoData)
            <img src="{{ $logoData }}" alt="{{ $branding['name'] }}" class="logo">
        @else
            <div class="agency">{{ $branding['name'] }}</div>
        @endif
        <h1 class="report-title">Buyers Report — {{ $scopeLabel }}</h1>
    </div>
    <div class="meta">
        <div><strong>{{ $branding['name'] }}</strong></div>
        <div>Scope: {{ $scopeLabel }}</div>
        <div>{{ ucfirst(str_replace('_', ' ', $preset)) }}: {{ $periodLabel }}</div>
        @if(($compareMode ?? 'off') !== 'off' && !empty($comparisonMeta))
            <div>Compared to: {{ $comparisonMeta['period']['label'] ?? $comparisonMeta['phrase'] ?? '' }}</div>
        @endif
        @if($type)
            <div>Type filter: {{ $types[$type] }} only</div>
        @endif
        <div>Generated {{ $generatedAt->format('d M Y, H:i') }}</div>
    </div>
</div>
<div class="print-actions">
    <button type="button" onclick="window.print()">Print / Save PDF</button>
</div>

{{-- ══════ SECTION A — What happened to buyers (period) ══════ --}}
<h2 class="section">What happened to buyers</h2>
<div class="cards">
    @foreach([
        ['buyers', 'Buyers held'], ['buyers_added', 'Buyers added'], ['buyers_won', 'Buyers won'],
        ['appointments', 'Appointments'], ['comms_email', 'Emails'], ['comms_whatsapp', 'WhatsApps'],
        ['lost', 'Buyers lost (real)'], ['lost_value', 'Value lost (real)'],
    ] as [$key, $label])
        @php
            $c = $comparison['company'][$key] ?? null;
            $notCaptured = $key === 'lost_value' && empty($m['lost_value_captured']);
        @endphp
        <div class="card">
            <div class="v">
                @if($notCaptured)
                    Not captured
                @else
                    {{ $key === 'lost_value' ? $money($m[$key] ?? 0) : number_format((float) ($m[$key] ?? 0)) }}
                @endif
            </div>
            <div class="k">{{ $label }}</div>
            @if($key === 'lost') <div class="k">{{ $m['lost_auto'] ?? 0 }} auto (housekeeping) not counted here</div> @endif
            @if(($compareMode ?? 'off') !== 'off')
                @php $d = $renderDelta($c, $comparisonMeta['phrase'] ?? '', $key === 'lost_value'); @endphp
                @if($d)
                    <div class="d {{ $d['cls'] }}">{{ $d['text'] }}</div>
                @endif
            @endif
        </div>
    @endforeach
</div>

<h2 class="section">Needs attention (top 10 per group)</h2>
@foreach([
    ['attention', 'Cold & lost buyers — longest-stuck first'],
    ['parked', 'Parked on purpose'],
    ['no_feedback', 'Viewed, no feedback captured'],
    ['recent_losses', 'Recently lost'],
] as [$key, $label])
    @if(!empty($attention[$key]))
        <div class="note"><strong>{{ $label }}</strong></div>
        @foreach(array_slice($attention[$key], 0, 10) as $row)
            <div class="list-item">
                {{ $row['name'] }}
                <span class="who">— {{ $row['agent_name'] ?? '' }}
                    @if(isset($row['days_in_state'])) · {{ $row['days_in_state'] }}d @endif
                    @if(isset($row['days_ago'])) · {{ $row['days_ago'] }}d ago @endif
                    @if(isset($row['reason'])) · {{ $row['reason'] }} @endif
                </span>
            </div>
        @endforeach
    @endif
@endforeach

<h2 class="section">By agent</h2>
<table>
    <thead>
        <tr><th class="l">Agent</th><th class="l">Branch</th><th>Held</th><th>Added</th><th>Won</th><th>Appts</th><th>Lost</th><th>Value lost</th></tr>
    </thead>
    <tbody>
        @forelse($report['agents'] as $a)
            <tr>
                <td class="l">{{ $a['name'] }}</td>
                <td class="l">{{ $a['branch_label'] ?? '' }}</td>
                <td>{{ $a['metrics']['buyers'] ?? 0 }}</td>
                <td>{{ $a['metrics']['buyers_added'] ?? 0 }}</td>
                <td>{{ $a['metrics']['buyers_won'] ?? 0 }}</td>
                <td>{{ $a['metrics']['appointments'] ?? 0 }}</td>
                <td>{{ $a['metrics']['lost'] ?? 0 }}</td>
                <td>{{ empty($a['metrics']['lost_value_captured']) ? 'Not captured' : $money($a['metrics']['lost_value'] ?? 0) }}</td>
            </tr>
        @empty
            <tr><td colspan="8" class="l">No agents in this scope.</td></tr>
        @endforelse
    </tbody>
</table>

@if(($scope->level ?? null) === \App\Services\BuyersReport\BuyersReportScope::LEVEL_AGENCY && count($report['branches'] ?? []) > 1)
<h2 class="section">By branch</h2>
<table>
    <thead>
        <tr><th class="l">Branch</th><th>Held</th><th>Added</th><th>Won</th><th>Appts</th><th>Lost</th><th>Value lost</th></tr>
    </thead>
    <tbody>
        @foreach($report['branches'] as $b)
            <tr>
                <td class="l">{{ $b['label'] ?? '' }}</td>
                <td>{{ $b['metrics']['buyers'] ?? 0 }}</td>
                <td>{{ $b['metrics']['buyers_added'] ?? 0 }}</td>
                <td>{{ $b['metrics']['buyers_won'] ?? 0 }}</td>
                <td>{{ $b['metrics']['appointments'] ?? 0 }}</td>
                <td>{{ $b['metrics']['lost'] ?? 0 }}</td>
                <td>{{ empty($b['metrics']['lost_value_captured']) ? 'Not captured' : $money($b['metrics']['lost_value'] ?? 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endif

{{-- ══════ SECTION B — What buyers do we have now (snapshot) ══════ --}}
<h2 class="section">What buyers do we have now</h2>
<div class="note">Current state, right now — matches the Buyer Pipeline board exactly (not a period figure).</div>
<div class="cards">
    @foreach(\App\Services\BuyersReport\PipelineStateService::STATES as $key => $label)
        <div class="card">
            <div class="v">{{ number_format($pipelineSnapshot['states'][$key] ?? 0) }}</div>
            <div class="k">{{ $label }}</div>
        </div>
    @endforeach
    @if(($pipelineSnapshot['no_state'] ?? 0) > 0)
        <div class="card">
            <div class="v">{{ number_format($pipelineSnapshot['no_state']) }}</div>
            <div class="k">No state recorded</div>
        </div>
    @endif
</div>
@php $hvp = $heldVsPipeline ?? ['report_held' => 0, 'pipeline_total' => 0, 'gap' => 0]; @endphp
<div class="note">
    Reconciliation — "Buyers held" tile vs. the pipeline board's total, same scope:
    {{ $hvp['report_held'] }} held · {{ $hvp['pipeline_total'] }} on the board
    {{ $hvp['gap'] > 0 ? '— ' . $hvp['gap'] . ' buyer' . ($hvp['gap'] === 1 ? '' : 's') . ' excluded from performance reporting (unassigned/inactive/owner-role agent)' : '— match' }}.
</div>

<h2 class="section">Demand analysis</h2>
@php
    $covered = ($demandCoverage['total_buyers'] ?? 0) - ($demandCoverage['no_match'] ?? 0);
@endphp
<div class="caveat">
    Base for this section: {{ $demandCoverage['total_buyers'] ?? 0 }} current buyers ·
    {{ $demandCoverage['no_match'] ?? 0 }} have no recorded requirement at all (not filterable) ·
    {{ $demandCoverage['no_type'] ?? 0 }} have no property type recorded ·
    {{ $demandCoverage['no_price'] ?? 0 }} have no price range recorded.
    Demand numbers below reflect only buyers with a recorded requirement — read them against this base, not the full buyer count.
</div>
@if($demandFilterActive)
    <div class="note">
        <strong>Filtered to:</strong>
        {{ !empty($demandTypes) ? implode(', ', $demandTypes) : 'any property type' }}
        @if($demandPriceMin !== null || $demandPriceMax !== null)
            , {{ $demandPriceMin !== null ? $money($demandPriceMin) : 'no floor' }} – {{ $demandPriceMax !== null ? $money($demandPriceMax) : 'no ceiling' }}
        @endif
        — {{ $demandResult['count'] }} buyer{{ $demandResult['count'] === 1 ? '' : 's' }} match this selection (overlap: a buyer's range only needs to touch the selection, not sit inside it).
    </div>
    <table>
        <thead><tr><th class="l">Buyer</th><th class="l">Agent</th><th class="l">Looking for</th><th>Range</th></tr></thead>
        <tbody>
            @forelse($demandResult['rows'] as $r)
                <tr>
                    <td class="l">{{ $r['name'] }}</td>
                    <td class="l">{{ $r['agent'] }}</td>
                    <td class="l">{{ $r['types'] }}</td>
                    <td>{{ $r['price_min'] !== null ? $money($r['price_min']) : 'no floor' }} – {{ $r['price_max'] !== null ? $money($r['price_max']) : 'no ceiling' }}</td>
                </tr>
            @empty
                <tr><td colspan="4" class="l">No current buyer's requirement overlaps this selection.</td></tr>
            @endforelse
        </tbody>
    </table>
    @if($demandResult['truncated'])
        <div class="note">Showing the first {{ count($demandResult['rows']) }} — narrow the selection to see the rest.</div>
    @endif
@else
    <div class="note">No property-type or price filter was applied when this report was generated — showing the demand facets available, not an unfiltered buyer-by-buyer list.</div>
    <div class="note">Property types in this scope: {{ !empty($demandFacets['types']) ? implode(', ', $demandFacets['types']) : 'none recorded' }}.</div>
@endif

<div class="foot">
    Buyers Report — {{ $branding['name'] }} — {{ $scopeLabel }} — generated {{ $generatedAt->format('d M Y, H:i') }}.
    Emails/WhatsApps are a floor, not a true count — only messages sent through a connected device or mailbox are captured.
    "Lost" is real losses only; auto-transitioned (no-activity) buyers are housekeeping, not a business outcome.
</div>

</body>
</html>
