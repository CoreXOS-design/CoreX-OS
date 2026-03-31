<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Deposit Interest Statement - {{ $input['property_name'] }}</title>
    <style>
        @page {
            size: A4;
            margin: 15mm 18mm;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #222;
            line-height: 1.5;
            background: #fff;
        }

        /* Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #0891b2;
            padding-bottom: 12px;
            margin-bottom: 20px;
        }
        .header-left {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .header-logo img {
            max-height: 60px;
            max-width: 180px;
        }
        .header-title {
            font-size: 18pt;
            font-weight: bold;
            color: #0891b2;
        }
        .header-date {
            font-size: 9pt;
            color: #666;
            text-align: right;
        }

        /* Details */
        .details {
            margin-bottom: 20px;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
        }
        .details-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px 40px;
        }
        .detail-label {
            font-size: 8pt;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .detail-value {
            font-size: 10pt;
            font-weight: bold;
            color: #0f172a;
        }

        /* Table */
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 9pt;
        }
        .breakdown-table thead th {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 6px 8px;
            text-align: left;
            font-size: 7.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            font-weight: 600;
        }
        .breakdown-table thead th.text-right {
            text-align: right;
        }
        .breakdown-table tbody td {
            border: 1px solid #e2e8f0;
            padding: 5px 8px;
        }
        .breakdown-table tbody tr:nth-child(even) {
            background: #f8fafc;
        }
        .breakdown-table tbody tr.row-deposit {
            background: #ecfeff;
        }
        .breakdown-table tbody tr.row-topup {
            background: #fffbeb;
        }
        .text-right { text-align: right; }
        .font-mono { font-family: 'Courier New', Courier, monospace; }
        .font-bold { font-weight: bold; }

        /* Summary */
        .summary {
            margin-top: 20px;
            border-top: 2px solid #0891b2;
            padding-top: 14px;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            font-size: 10pt;
        }
        .summary-row.total {
            border-top: 2px solid #0f172a;
            margin-top: 6px;
            padding-top: 8px;
            font-size: 12pt;
            font-weight: bold;
        }
        .summary-label { color: #475569; }
        .summary-value { font-family: 'Courier New', Courier, monospace; font-weight: bold; }

        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 8pt;
            color: #94a3b8;
        }
    </style>
</head>
<body>
    {{-- Header --}}
    <div class="header">
        <div class="header-left">
            @if($logoBase64)
                <div class="header-logo">
                    <img src="{{ $logoBase64 }}" alt="{{ $agency->name ?? 'Agency' }}">
                </div>
            @endif
            <div>
                <div class="header-title">Deposit Interest Statement</div>
                @if($agency)
                    <div style="font-size: 9pt; color: #64748b;">{{ $agency->name ?? '' }}</div>
                @endif
            </div>
        </div>
        <div class="header-date">
            Generated: {{ $generatedDate }}
        </div>
    </div>

    {{-- Property Details --}}
    <div class="details">
        <div class="details-grid">
            <div>
                <div class="detail-label">Property</div>
                <div class="detail-value">{{ $input['property_name'] }}</div>
            </div>
            <div>
                <div class="detail-label">Deposit Amount</div>
                <div class="detail-value">R {{ number_format($result['deposit_amount'], 2) }}</div>
            </div>
            <div>
                <div class="detail-label">Deposit Invested</div>
                <div class="detail-value">{{ \Carbon\Carbon::parse($input['invest_date'])->format('d F Y') }}</div>
            </div>
            <div>
                <div class="detail-label">Deposit Refunded</div>
                <div class="detail-value">{{ \Carbon\Carbon::parse($input['refund_date'])->format('d F Y') }}</div>
            </div>
            <div>
                <div class="detail-label">Topups</div>
                <div class="detail-value">{{ $result['topups_total'] > 0 ? 'R ' . number_format($result['topups_total'], 2) : 'None' }}</div>
            </div>
        </div>
    </div>

    {{-- Breakdown Table --}}
    <table class="breakdown-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="text-right">Total Invested Funds</th>
                <th class="text-right">Running Balance</th>
                <th class="text-right">Share %</th>
                <th class="text-right">Interest Earned</th>
                <th class="text-right">Share of Interest</th>
            </tr>
        </thead>
        <tbody>
            @foreach($result['breakdown'] as $row)
                <tr class="{{ $row['type'] === 'deposit' ? 'row-deposit' : ($row['type'] === 'topup' ? 'row-topup' : '') }}">
                    <td>{{ $row['date']->format('d M Y') }}</td>
                    <td class="font-bold">{{ $row['description'] }}</td>
                    <td class="text-right font-mono">{{ $row['total_invested_funds'] !== null ? 'R ' . number_format($row['total_invested_funds'], 2) : '—' }}</td>
                    <td class="text-right font-mono font-bold">R {{ number_format($row['running_balance'], 2) }}</td>
                    <td class="text-right font-mono">{{ $row['share_percentage'] !== null ? number_format($row['share_percentage'] * 100, 4) . '%' : '—' }}</td>
                    <td class="text-right font-mono">{{ $row['interest_earned'] !== null ? 'R ' . number_format($row['interest_earned'], 2) : '—' }}</td>
                    <td class="text-right font-mono font-bold">{{ $row['interest_share'] !== null ? 'R ' . number_format($row['interest_share'], 2) : '—' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Summary --}}
    <div class="summary">
        <div class="summary-row">
            <span class="summary-label">Total Deposit (incl. topups)</span>
            <span class="summary-value">R {{ number_format($result['total_deposited'], 2) }}</span>
        </div>
        <div class="summary-row">
            <span class="summary-label">Total Interest Earned</span>
            <span class="summary-value">R {{ number_format($result['total_interest'], 2) }}</span>
        </div>
        <div class="summary-row total">
            <span>Grand Total Payable</span>
            <span class="summary-value">R {{ number_format($result['grand_total'], 2) }}</span>
        </div>
    </div>

    {{-- Footer --}}
    <div class="footer">
        <p>This statement was generated by CoreX OS on {{ $generatedDate }}.</p>
        <p>Interest calculated as a proportional share of the agency trust account investment.</p>
    </div>
</body>
</html>
