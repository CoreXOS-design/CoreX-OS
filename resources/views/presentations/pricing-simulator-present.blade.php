<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pricing Analysis — {{ $presentation->title }}</title>
    <style>
        :root {
            --brand: #4338ca;
            --brand-light: #e0e7ff;
            --text: #1f2937;
            --text-muted: #6b7280;
            --text-light: #9ca3af;
            --bg: #ffffff;
            --bg-alt: #f9fafb;
            --border: #e5e7eb;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text);
            background: var(--bg);
            padding: 40px;
            max-width: 1000px;
            margin: 0 auto;
            font-size: 14px;
            line-height: 1.5;
        }
        h1 { font-size: 24px; font-weight: 700; color: var(--brand); margin-bottom: 4px; }
        h2 { font-size: 16px; font-weight: 600; margin-bottom: 12px; }
        .subtitle { font-size: 13px; color: var(--text-muted); margin-bottom: 24px; }
        .config-summary {
            font-size: 11px; color: var(--text-light);
            margin-bottom: 20px; padding: 8px 12px;
            background: var(--bg-alt); border: 1px solid var(--border); border-radius: 6px;
        }
        table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
        th { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted);
             padding: 8px 10px; border-bottom: 2px solid var(--border); text-align: left; }
        td { padding: 8px 10px; border-bottom: 1px solid var(--border); font-size: 13px; }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .bold { font-weight: 700; }
        .text-green { color: #059669; }
        .text-red { color: #dc2626; }

        .prob-badge {
            display: inline-block; font-size: 11px; padding: 2px 8px;
            border-radius: 999px; font-weight: 600;
        }
        .prob-very-likely { background: #d1fae5; color: #059669; }
        .prob-likely { background: #dcfce7; color: #16a34a; }
        .prob-possible { background: #fef3c7; color: #d97706; }
        .prob-unlikely { background: #fed7aa; color: #ea580c; }
        .prob-very-unlikely { background: #fecaca; color: #dc2626; }

        .bar-chart { margin-bottom: 24px; }
        .bar-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
        .bar-label { width: 120px; text-align: right; font-size: 12px; color: var(--text-muted); flex-shrink: 0; }
        .bar-track { flex: 1; background: #f3f4f6; border-radius: 999px; height: 28px; overflow: hidden; position: relative; }
        .bar-fill { height: 100%; border-radius: 999px; display: flex; align-items: center; padding: 0 8px; transition: width 0.3s; }
        .bar-fill span { font-size: 11px; color: white; font-weight: 600; white-space: nowrap; }

        .narrative {
            background: var(--brand-light); border: 1px solid #c7d2fe; border-radius: 8px;
            padding: 16px; margin-bottom: 24px;
        }
        .narrative h3 { font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--brand); margin-bottom: 8px; }
        .narrative p { font-size: 13px; color: #312e81; line-height: 1.6; }

        .footer {
            margin-top: 32px; padding-top: 12px; border-top: 1px solid var(--border);
            text-align: center; font-size: 10px; color: var(--text-light);
        }

        @media print {
            body { padding: 20px; }
            .no-print { display: none !important; }
        }
    </style>
</head>
<body>

<h1>Pricing Analysis</h1>
<p class="subtitle">
    {{ $presentation->property_address ?? $presentation->title }}
    @if($presentation->suburb)
        &middot; {{ $presentation->suburb }}
    @endif
</p>

<div class="config-summary">
    Commission: {{ $config['commission_pct'] ?? 7.5 }}% (excl. VAT)
    &middot; Transfer Cost: {{ $config['transfer_cost_pct'] ?? 4.0 }}%
    &middot; Monthly Holding Cost: R {{ number_format($config['monthly_holding_cost'] ?? 0, 0, '.', ' ') }}
</div>

@if(!empty($stock['total_active_stock']) && !empty($stock['monthly_sales']))
@php
    $stockBg = match($stock['absorption_color'] ?? '') {
        'green'  => '#ecfdf5', 'amber' => '#fffbeb', 'orange' => '#fff7ed', 'red' => '#fef2f2', default => '#f9fafb',
    };
    $stockBorder = match($stock['absorption_color'] ?? '') {
        'green'  => '#a7f3d0', 'amber' => '#fde68a', 'orange' => '#fed7aa', 'red' => '#fecaca', default => '#e5e7eb',
    };
    $stockText = match($stock['absorption_color'] ?? '') {
        'green'  => '#065f46', 'amber' => '#92400e', 'orange' => '#9a3412', 'red' => '#991b1b', default => '#374151',
    };
@endphp
<div style="background:{{ $stockBg }};border:1px solid {{ $stockBorder }};border-radius:6px;padding:12px 16px;margin-bottom:20px;color:{{ $stockText }};font-size:13px;">
    <strong>{{ $stock['total_active_stock'] }}</strong> competing listings
    &middot; <strong>{{ $stock['annual_sales'] }}</strong> sales/year ({{ number_format($stock['monthly_sales'], 1) }}/month)
    &middot; <strong>{{ number_format($stock['months_of_supply'], 1) }}</strong> months of supply
    &middot; <strong>{{ $stock['absorption_label'] }}</strong>
</div>
@endif

{{-- ── Scenarios Table ─────────────────────────────────────────── --}}
<h2>Pricing Scenarios</h2>
<table>
    <thead>
        <tr>
            <th>Scenario</th>
            <th class="num">Price</th>
            <th class="num">Competing</th>
            <th class="num">Est. Months</th>
            <th class="num">Holding Cost</th>
            <th class="num">Commission</th>
            <th class="num">Transfer</th>
            <th class="num">Net Proceeds</th>
            <th class="num">vs Asking</th>
            <th>Probability</th>
        </tr>
    </thead>
    <tbody>
        @foreach($scenarios as $s)
        <tr>
            <td>{{ $s['label'] }}</td>
            <td class="num">R {{ number_format($s['price'] ?? 0, 0, '.', ' ') }}</td>
            <td class="num">{{ $s['competing_count'] ?? '—' }}</td>
            <td class="num">{{ $s['est_months'] ?? '—' }}</td>
            <td class="num">R {{ number_format($s['holding_cost_total'] ?? 0, 0, '.', ' ') }}</td>
            <td class="num">R {{ number_format($s['commission'] ?? 0, 0, '.', ' ') }}</td>
            <td class="num">R {{ number_format($s['transfer_cost'] ?? 0, 0, '.', ' ') }}</td>
            <td class="num bold {{ ($s['net_proceeds'] ?? 0) >= 0 ? 'text-green' : 'text-red' }}">
                R {{ number_format($s['net_proceeds'] ?? 0, 0, '.', ' ') }}
            </td>
            <td class="num {{ ($s['vs_asking_net'] ?? 0) >= 0 ? 'text-green' : 'text-red' }}">
                @if(isset($s['vs_asking_net']))
                    {{ $s['vs_asking_net'] >= 0 ? '+' : '' }}R {{ number_format($s['vs_asking_net'] ?? 0, 0, '.', ' ') }}
                @else
                    —
                @endif
            </td>
            <td>
                @php
                    $pSlug = strtolower(str_replace(' ', '-', $s['probability'] ?? 'unknown'));
                @endphp
                <span class="prob-badge prob-{{ $pSlug }}">{{ $s['probability'] ?? '—' }}</span>
            </td>
        </tr>
        @endforeach
    </tbody>
</table>

{{-- ── Bar Chart ───────────────────────────────────────────────── --}}
<h2>Net Proceeds Comparison</h2>
<div class="bar-chart">
    @php
        $maxNet = max(1, max(array_map(fn($s) => max($s['net_proceeds'] ?? 0, 0), $scenarios)));
        $barColors = [
            'Very Likely' => '#059669', 'Likely' => '#16a34a',
            'Possible'    => '#d97706', 'Unlikely' => '#ea580c', 'Very Unlikely' => '#dc2626',
        ];
    @endphp
    @foreach($scenarios as $s)
        @php
            $widthPct = max(2, round((max($s['net_proceeds'] ?? 0, 0) / $maxNet) * 100));
            $color = $barColors[$s['probability'] ?? ''] ?? '#dc2626';
        @endphp
        <div class="bar-row">
            <div class="bar-label">{{ $s['label'] }}</div>
            <div class="bar-track">
                <div class="bar-fill" style="width:{{ $widthPct }}%;background:{{ $color }};">
                    <span>R {{ number_format($s['net_proceeds'] ?? 0, 0, '.', ' ') }}</span>
                </div>
            </div>
        </div>
    @endforeach
</div>

{{-- ── Narrative ───────────────────────────────────────────────── --}}
@if($narrative)
<div class="narrative">
    <h3>Key Insight</h3>
    <p>{{ $narrative }}</p>
</div>
@endif

{{-- ── Footer ──────────────────────────────────────────────────── --}}
<div class="footer">
    Prepared by {{ $agentName }} &middot; Home Finders Coastal &middot; {{ now()->format('d M Y') }}
    <br>
    This analysis is based on publicly available data and independent CMA evaluation. All values in South African Rand (ZAR).
</div>

</body>
</html>
