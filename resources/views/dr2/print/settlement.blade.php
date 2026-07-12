<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Settlement Breakdown - Deal #{{ $deal->deal_no }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @page { size: A4; margin: 14mm; }
    body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; color:#0f172a; }
    .top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
    .h1 { margin:0; font-size:18px; font-weight:900; letter-spacing:-0.02em; color:#0b2a4a; }
    .sub { margin-top:4px; font-size:12px; color:#475569; }
    .meta { text-align:right; font-size:12px; color:#334155; }
    .pill { display:inline-block; padding:6px 10px; border-radius:999px; border:1px solid #e2e8f0; background:#fff; font-size:12px; }
    .card { border:1px solid #e2e8f0; border-radius:16px; padding:14px; margin-top:14px; border-left:4px solid #00b4d8; }
    .grid3 { display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px; }
    .k { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b; font-weight:600; }
    .v { font-size:18px; font-weight:900; margin-top:4px; color:#0b2a4a; }
    .cols { display:grid; grid-template-columns:1fr 1fr; gap:14px; margin-top:14px; }
    table { width:100%; border-collapse:collapse; font-size:12px; }
    th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b; padding:8px; border-bottom:1px solid #e2e8f0; font-weight:600; }
    td { padding:8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    tr:nth-child(even) td { background:#f8fafc; }
    .right { text-align:right; }
    .muted { color:#64748b; font-size:11px; }
    .net { font-weight:900; color:#0b2a4a; }
    .ok { color:#059669; font-weight:900; }
    .bad { color:#c41e3a; font-weight:900; }
    .agent-name { font-weight:800; color:#0b2a4a; }
    .header-bar { background:#0b2a4a; color:#fff; padding:12px 16px; border-radius:12px; margin-bottom:14px; }
    .header-bar .h1 { color:#fff; }
    .header-bar .sub { color:rgba(255,255,255,0.6); }
  </style>
</head>
<body>
  @php
    $money = fn($v) => number_format((float)($v ?? 0), 2, '.', ',');
    $property = $deal->property_address ?? $deal->address ?? $deal->property ?? $deal->title ?? ('Deal #' . $deal->deal_no);
  @endphp

  <div class="header-bar">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <div class="h1">{{ $companyName }} &mdash; Settlement Breakdown</div>
        <div class="sub">{{ $property }}</div>
        <div class="sub">Deal #{{ $deal->deal_no }}</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:12px; color:rgba(255,255,255,0.7)">Printed: {{ now()->format('Y-m-d H:i') }}</div>
        <div style="margin-top:4px; font-size:11px; color:rgba(255,255,255,0.5)">Checksum:</div>
        <div style="font-weight:900; color:{{ $checksumOk ? '#86efac' : '#fca5a5' }}">R {{ $money($checksumTotal) }} ({{ $checksumOk ? 'OK' : 'NOT OK' }})</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="grid3">
      <div>
        <div class="k">Commission (Incl VAT)</div>
        <div class="v">R {{ $money($totalCommissionIncVat) }}</div>
      </div>
      <div>
        <div class="k">VAT ({{ (int)round(((float)$vatRate)*100) }}%)</div>
        <div class="v">R {{ $money($vatAmt) }}</div>
      </div>
      <div>
        <div class="k">Commission (Ex VAT)</div>
        <div class="v">R {{ $money($totalCommissionExVat) }}</div>
      </div>
    </div>
  </div>

  <div class="cols">
    <div class="card">
      <div class="k">Listing Side (Our Pool Ex VAT)</div>
      <div class="v">R {{ $money($listingPool) }}</div>
      <div class="muted">External payable (Incl VAT): R {{ $money($listingExternalPayable ?? 0) }}</div>
      <table style="margin-top:10px">
        <thead>
          <tr>
            <th>Agent</th>
            <th class="right">Share %</th>
            <th class="right">Allocated</th>
            <th class="right">Cut %</th>
            <th class="right">PAYE</th>
            <th class="right">Deduct</th>
            <th class="right">Net</th>
          </tr>
        </thead>
        <tbody>
          @foreach($listingRows as $r)
            <tr>
              <td>
                <div class="agent-name">{{ $r['name'] }}</div>
                @if(!empty($r['deductions_description']))
                  <div class="muted">Deduction: {{ $r['deductions_description'] }}</div>
                @endif
              </td>
              <td class="right">{{ number_format((float)$r['share_percent'], 2) }}</td>
              <td class="right">R {{ $money($r['allocated']) }}</td>
              <td class="right">{{ number_format((float)$r['agent_cut_percent'], 2) }}</td>
              <td class="right">R {{ $money($r['paye']) }}</td>
              <td class="right">R {{ $money($r['deductions']) }}</td>
              <td class="right net">R {{ $money($r['net']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>

    <div class="card">
      <div class="k">Selling Side (Our Pool Ex VAT)</div>
      <div class="v">R {{ $money($sellingPool) }}</div>
      <div class="muted">External payable (Incl VAT): R {{ $money($sellingExternalPayable ?? 0) }}</div>
      <table style="margin-top:10px">
        <thead>
          <tr>
            <th>Agent</th>
            <th class="right">Share %</th>
            <th class="right">Allocated</th>
            <th class="right">Cut %</th>
            <th class="right">PAYE</th>
            <th class="right">Deduct</th>
            <th class="right">Net</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sellingRows as $r)
            <tr>
              <td>
                <div class="agent-name">{{ $r['name'] }}</div>
                @if(!empty($r['deductions_description']))
                  <div class="muted">Deduction: {{ $r['deductions_description'] }}</div>
                @endif
              </td>
              <td class="right">{{ number_format((float)$r['share_percent'], 2) }}</td>
              <td class="right">R {{ $money($r['allocated']) }}</td>
              <td class="right">{{ number_format((float)$r['agent_cut_percent'], 2) }}</td>
              <td class="right">R {{ $money($r['paye']) }}</td>
              <td class="right">R {{ $money($r['deductions']) }}</td>
              <td class="right net">R {{ $money($r['net']) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <div class="k">Payout Summary (Net to Agents)</div>
    <table style="margin-top:10px">
      <thead>
        <tr>
          <th>Agent</th>
          <th class="right">Allocated</th>
          <th class="right">Gross</th>
          <th class="right">PAYE</th>
          <th class="right">Deductions</th>
          <th class="right">Net</th>
        </tr>
      </thead>
      <tbody>
        @foreach($agentSummary as $s)
          @if((int)$s['user_id'] > 0)
            <tr>
              <td class="agent-name">{{ $s['name'] }}</td>
              <td class="right">R {{ $money($s['allocated']) }}</td>
              <td class="right">R {{ $money($s['gross']) }}</td>
              <td class="right">R {{ $money($s['paye']) }}</td>
              <td class="right">R {{ $money($s['deductions']) }}</td>
              <td class="right net">R {{ $money($s['net']) }}</td>
            </tr>
          @endif
        @endforeach
      </tbody>
    </table>
    <div class="muted" style="margin-top:10px">
      Company portion: R {{ $money($totals['company']) }} &bull; External payable (Incl VAT): R {{ $money($externalPayableTotal ?? 0) }}
    </div>
  </div>

  <script>window.print();</script>
</body>
</html>
