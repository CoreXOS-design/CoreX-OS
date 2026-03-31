<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Payslip - {{ $user->name }} - {{ $deal->reference }}</title>
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <style>
    @page { size: A4; margin: 14mm; }
    body { font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial; color:#0f172a; }
    .top { display:flex; justify-content:space-between; gap:16px; align-items:flex-start; }
    .h1 { margin:0; font-size:18px; font-weight:900; letter-spacing:-0.02em; color:#0b2a4a; }
    .sub { margin-top:4px; font-size:12px; color:#475569; }
    .pill { display:inline-block; padding:6px 10px; border-radius:999px; border:1px solid #e2e8f0; background:#fff; font-size:12px; }
    .card { border:1px solid #e2e8f0; border-radius:16px; padding:14px; margin-top:14px; border-left:4px solid #00b4d8; }
    .grid5 { display:grid; grid-template-columns:repeat(5, 1fr); gap:10px; }
    .k { font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b; font-weight:600; }
    .v { font-size:16px; font-weight:900; margin-top:4px; color:#0b2a4a; }
    table { width:100%; border-collapse:collapse; font-size:12px; margin-top:10px; }
    th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#64748b; padding:8px; border-bottom:1px solid #e2e8f0; font-weight:600; }
    td { padding:8px; border-bottom:1px solid #f1f5f9; vertical-align:top; }
    tr:nth-child(even) td { background:#f8fafc; }
    .right { text-align:right; }
    .muted { color:#64748b; font-size:11px; }
    .net { font-weight:900; color:#0b2a4a; }
    .agent-name { font-weight:800; color:#0b2a4a; }
    .header-bar { background:#0b2a4a; color:#fff; padding:12px 16px; border-radius:12px; margin-bottom:14px; }
    .header-bar .h1 { color:#fff; }
    .header-bar .sub { color:rgba(255,255,255,0.6); }
  </style>
</head>
<body>
  @php
    $money = fn($v) => number_format((float)($v ?? 0), 2, '.', ',');
    $property = $deal->property->address ?? ('Deal ' . $deal->reference);
  @endphp

  <div class="header-bar">
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <div class="h1">{{ $companyName }} &mdash; Agent Payslip</div>
        <div class="sub">{{ $user->name }} &bull; {{ $deal->reference }}</div>
        <div class="sub">{{ $property }}</div>
      </div>
      <div style="text-align:right">
        <div style="font-size:12px; color:rgba(255,255,255,0.7)">Printed: {{ now()->format('Y-m-d H:i') }}</div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="grid5">
      <div><div class="k">Allocated</div><div class="v">R {{ $money($mine['allocated']) }}</div></div>
      <div><div class="k">Gross</div><div class="v">R {{ $money($mine['gross']) }}</div></div>
      <div><div class="k">PAYE</div><div class="v">R {{ $money($mine['paye']) }}</div></div>
      <div><div class="k">Deductions</div><div class="v">R {{ $money($mine['deductions']) }}</div></div>
      <div><div class="k">Net (Payable)</div><div class="v">R {{ $money($mine['net']) }}</div></div>
    </div>
    <div class="muted" style="margin-top:10px">
      Commission incl VAT: R {{ $money($totalCommissionIncVat) }} &bull; VAT: R {{ $money($vatAmt) }} &bull; External payable (incl VAT): R {{ $money($externalPayableTotal ?? 0) }}
    </div>
  </div>

  <div class="card">
    <div class="k">Breakdown</div>
    <table>
      <thead>
        <tr>
          <th>Side</th>
          <th class="right">Share %</th>
          <th class="right">Allocated</th>
          <th class="right">Cut %</th>
          <th class="right">PAYE</th>
          <th class="right">Deduct</th>
          <th class="right">Net</th>
        </tr>
      </thead>
      <tbody>
        @foreach($listingMine as $r)
          <tr>
            <td>
              <div class="agent-name">Listing</div>
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

        @foreach($sellingMine as $r)
          <tr>
            <td>
              <div class="agent-name">Selling</div>
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

        @if(count($listingMine) === 0 && count($sellingMine) === 0)
          <tr>
            <td colspan="7" class="muted">No settlement rows found for this agent on this deal.</td>
          </tr>
        @endif
      </tbody>
    </table>
  </div>

  <script>window.print();</script>
</body>
</html>
