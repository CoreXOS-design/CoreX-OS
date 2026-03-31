<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Deposit Interest Statement - {{ $input['property_name'] }}</title>
    <style>
        @page {
            size: A4;
            margin: 15mm 18mm 20mm 18mm;
            @bottom-center {
                content: "Page " counter(page) " of " counter(pages);
                font-family: Arial, Helvetica, sans-serif;
                font-size: 8pt;
                color: #94a3b8;
            }
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 10pt;
            color: #1e293b;
            line-height: 1.6;
            background: #fff;
        }

        /* Header */
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-bottom: 2px solid #0891b2;
            padding-bottom: 14px;
            margin-bottom: 22px;
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
            font-size: 17pt;
            font-weight: bold;
            color: #0891b2;
        }
        .header-subtitle {
            font-size: 9pt;
            color: #64748b;
            margin-top: 2px;
        }
        .header-date {
            font-size: 9pt;
            color: #64748b;
            text-align: right;
        }

        /* Details */
        .details {
            margin-bottom: 20px;
        }
        .details-row {
            display: flex;
            gap: 40px;
            margin-bottom: 4px;
        }
        .details-row .label {
            font-size: 9pt;
            color: #64748b;
            min-width: 140px;
        }
        .details-row .value {
            font-size: 10pt;
            font-weight: 600;
            color: #0f172a;
        }

        /* Summary box */
        .summary-box {
            margin-bottom: 28px;
            padding: 18px 22px;
            background: #f8f9fa;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
        }
        .summary-box-title {
            font-size: 9pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            color: #475569;
            margin-bottom: 12px;
        }
        .summary-line {
            display: flex;
            justify-content: space-between;
            padding: 3px 0;
            font-size: 10pt;
        }
        .summary-line .label { color: #475569; }
        .summary-line .value { font-family: 'Courier New', Courier, monospace; font-weight: bold; color: #0f172a; min-width: 120px; text-align: right; }
        .summary-divider {
            border-top: 1px solid #94a3b8;
            margin: 8px 0;
        }
        .summary-total {
            display: flex;
            justify-content: space-between;
            padding: 8px 0 0;
            font-size: 13pt;
            font-weight: bold;
        }
        .summary-total .label { color: #0f172a; }
        .summary-total .value { font-family: 'Courier New', Courier, monospace; color: #0891b2; min-width: 120px; text-align: right; }

        /* Transaction history heading */
        .section-heading {
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: #475569;
            margin-bottom: 10px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }

        /* Table */
        .breakdown-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 24px;
            font-size: 9.5pt;
        }
        .breakdown-table thead th {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 7px 10px;
            text-align: left;
            font-size: 8pt;
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
            padding: 6px 10px;
        }
        .breakdown-table tbody tr {
            break-inside: avoid;
        }
        .breakdown-table tbody tr:nth-child(even) {
            background: #fafbfc;
        }
        .breakdown-table tbody tr.row-deposit {
            background: #f0fdfa;
        }
        .breakdown-table tbody tr.row-topup {
            background: #fefce8;
        }
        .text-right { text-align: right; }
        .font-mono { font-family: 'Courier New', Courier, monospace; }
        .font-bold { font-weight: bold; }

        /* Footer */
        .footer {
            margin-top: 30px;
            padding-top: 10px;
            border-top: 1px solid #e2e8f0;
            font-size: 8pt;
            color: #94a3b8;
            line-height: 1.5;
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
                <div class="header-title">Rental Deposit Interest Statement</div>
                <div class="header-subtitle">Prepared for the tenant of {{ $input['property_name'] }}</div>
            </div>
        </div>
        <div class="header-date">
            {{ $generatedDate }}
        </div>
    </div>

    {{-- Property Details --}}
    <div class="details">
        <div class="details-row">
            <span class="label">Property</span>
            <span class="value">{{ $input['property_name'] }}</span>
        </div>
        <div class="details-row">
            <span class="label">Deposit Invested</span>
            <span class="value">{{ \Carbon\Carbon::parse($input['invest_date'])->format('d F Y') }}</span>
        </div>
        <div class="details-row">
            <span class="label">Deposit Refunded</span>
            <span class="value">{{ \Carbon\Carbon::parse($input['refund_date'])->format('d F Y') }}</span>
        </div>
    </div>

    {{-- Summary Box --}}
    <div class="summary-box">
        <div class="summary-box-title">Deposit Summary</div>
        <div class="summary-line">
            <span class="label">Original Deposit</span>
            <span class="value">R {{ number_format($result['deposit_amount'], 2) }}</span>
        </div>
        @if($result['topups_total'] > 0)
        <div class="summary-line">
            <span class="label">Additional Deposits</span>
            <span class="value">R {{ number_format($result['topups_total'], 2) }}</span>
        </div>
        @endif
        <div class="summary-line">
            <span class="label">Interest Earned</span>
            <span class="value">R {{ number_format($result['total_interest'], 2) }}</span>
        </div>
        <div class="summary-divider"></div>
        <div class="summary-total">
            <span class="label">Total Payable to Tenant</span>
            <span class="value">R {{ number_format($result['grand_total'], 2) }}</span>
        </div>
    </div>

    {{-- Transaction History --}}
    <div class="section-heading">Transaction History</div>

    <table class="breakdown-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Description</th>
                <th class="text-right">Interest Earned</th>
                <th class="text-right">Balance</th>
            </tr>
        </thead>
        <tbody>
            @foreach($result['breakdown'] as $row)
                <tr class="{{ $row['type'] === 'deposit' ? 'row-deposit' : ($row['type'] === 'topup' ? 'row-topup' : '') }}">
                    <td>{{ $row['date']->format('d F Y') }}</td>
                    <td class="font-bold">
                        @if($row['type'] === 'deposit')
                            Deposit Invested
                        @elseif($row['type'] === 'topup')
                            Additional Deposit
                        @else
                            Interest Earned
                        @endif
                    </td>
                    <td class="text-right font-mono">
                        @if($row['type'] === 'interest')
                            R {{ number_format($row['interest_share'], 2) }}
                        @else
                            —
                        @endif
                    </td>
                    <td class="text-right font-mono font-bold">R {{ number_format($row['running_balance'], 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Footer --}}
    <div class="footer">
        <p>This statement was generated by Home Finders Coastal on {{ $generatedDate }}.</p>
        <p>Interest is calculated monthly as a proportional share of the trust investment account in accordance with the Rental Housing Act.</p>
    </div>
</body>
</html>
