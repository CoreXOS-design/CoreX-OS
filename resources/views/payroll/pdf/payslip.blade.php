<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip {{ $payslip->payslip_number }}</title>
    <style>
        @page { size: A4; margin: 15mm 12mm; }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 10.5pt; color: #1e293b; line-height: 1.4; }
        .header { border-bottom: 2px solid #00d4aa; padding-bottom: 12pt; margin-bottom: 14pt; }
        .header h1 { font-size: 16pt; margin: 0; color: #0f172a; }
        .header .reg-info { font-size: 8.5pt; color: #475569; margin-top: 4pt; line-height: 1.5; }
        .meta-bar { display: flex; justify-content: space-between; background: #f1f5f9; padding: 8pt 12pt; margin-bottom: 14pt; border-radius: 3px; }
        .meta-bar .cell { }
        .meta-bar .label { font-size: 8pt; color: #64748b; text-transform: uppercase; letter-spacing: 0.5pt; }
        .meta-bar .value { font-size: 11pt; font-weight: 600; color: #0f172a; }
        .employee-block { display: grid; grid-template-columns: 120pt 1fr; gap: 3pt 8pt; margin-bottom: 16pt; font-size: 10pt; }
        .employee-block .label { color: #64748b; }
        .employee-block .value { font-weight: 500; color: #0f172a; }
        .section-title { font-size: 11pt; font-weight: 700; color: #00d4aa; text-transform: uppercase; letter-spacing: 0.5pt; border-bottom: 1px solid #e2e8f0; padding-bottom: 4pt; margin-bottom: 6pt; margin-top: 12pt; }
        table.lines { width: 100%; border-collapse: collapse; margin-bottom: 8pt; }
        table.lines td { padding: 5pt 4pt; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
        table.lines td.amount { text-align: right; font-variant-numeric: tabular-nums; }
        table.lines td.code { font-size: 9pt; color: #94a3b8; width: 50pt; }
        table.lines tr.subtotal td { border-top: 1px solid #cbd5e1; border-bottom: none; padding-top: 6pt; font-weight: 600; }
        .net-pay { background: #0f172a; color: #00d4aa; padding: 12pt; border-radius: 3px; display: flex; justify-content: space-between; align-items: center; margin: 12pt 0; }
        .net-pay .label { font-size: 11pt; font-weight: 600; }
        .net-pay .value { font-size: 18pt; font-weight: 700; font-variant-numeric: tabular-nums; }
        .employer-contributions { font-size: 9pt; color: #64748b; margin-top: 8pt; padding: 6pt 8pt; background: #f8fafc; border-radius: 3px; }
        .employer-contributions strong { color: #475569; }
        .employer-contributions table { width: 100%; margin-top: 3pt; }
        .employer-contributions td { padding: 2pt 0; }
        .employer-contributions td.amount { text-align: right; }
        .footer { margin-top: 16pt; padding-top: 8pt; border-top: 1px solid #e2e8f0; font-size: 8pt; color: #94a3b8; display: flex; justify-content: space-between; }
        .preview-watermark { position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%) rotate(-30deg); font-size: 96pt; color: #00d4aa; opacity: 0.08; pointer-events: none; z-index: 0; }
    </style>
</head>
<body>
    @if(!$payslip->run->isFinalised())
        <div class="preview-watermark">PREVIEW</div>
    @endif

    {{-- Agency header --}}
    <div class="header">
        <h1>{{ $agency->trading_name ?? $agency->name }}</h1>
        <div class="reg-info">
            @if($agency->reg_no)Reg: {{ $agency->reg_no }}@endif
            @if($agency->vat_no) | VAT: {{ $agency->vat_no }}@endif
            @if($agency->paye_registration_no) | PAYE: {{ $agency->paye_registration_no }}@endif
            @if($agency->uif_employer_no) | UIF: {{ $agency->uif_employer_no }}@endif
            <br>
            @if($agency->address){{ $agency->address }}@endif
            @if($agency->phone) | {{ $agency->phone }}@endif
            @if($agency->email) | {{ $agency->email }}@endif
        </div>
    </div>

    {{-- Meta bar --}}
    <div class="meta-bar">
        <div class="cell">
            <div class="label">Payslip</div>
            <div class="value">{{ $payslip->payslip_number }}</div>
        </div>
        <div class="cell">
            <div class="label">Period</div>
            <div class="value">{{ $payslip->period_month->format('F Y') }}</div>
        </div>
        <div class="cell">
            <div class="label">Pay Date</div>
            <div class="value">{{ $payslip->pay_date->format('j M Y') }}</div>
        </div>
    </div>

    {{-- Employee block --}}
    <div class="employee-block">
        <div class="label">Employee:</div>
        <div class="value">{{ $payslip->employee_name_snapshot }}</div>

        @if($payslip->id_number_snapshot)
        <div class="label">ID Number:</div>
        <div class="value">{{ $payslip->id_number_snapshot }}</div>
        @endif

        @if($payslip->tax_reference_snapshot)
        <div class="label">Tax Reference:</div>
        <div class="value">{{ $payslip->tax_reference_snapshot }}</div>
        @else
        <div class="label">Tax Reference:</div>
        <div class="value" style="color: #94a3b8;">[Pending SARS registration]</div>
        @endif

        <div class="label">Designation:</div>
        <div class="value">{{ $payslip->designation_snapshot }}</div>

        <div class="label">Employed:</div>
        <div class="value">{{ $payslip->employment_date_snapshot?->format('j M Y') ?? '-' }}</div>

        @if($banking)
        <div class="label">Banking:</div>
        <div class="value">{{ $banking->bank_name }} &bull;&bull;&bull;{{ substr($banking->account_number ?? '', -4) }}</div>
        @endif
    </div>

    {{-- Earnings --}}
    <div class="section-title">Earnings</div>
    <table class="lines">
        @foreach($payslip->earnings as $line)
        <tr>
            <td class="code">{{ $line->sars_source_code_snapshot ?? '' }}</td>
            <td>{{ $line->label_snapshot }}</td>
            <td class="amount">R {{ number_format($line->amount, 2, '.', ',') }}</td>
        </tr>
        @endforeach
        <tr class="subtotal">
            <td></td>
            <td>Total Earnings</td>
            <td class="amount">R {{ number_format($payslip->total_earnings, 2, '.', ',') }}</td>
        </tr>
    </table>

    {{-- Deductions --}}
    <div class="section-title">Deductions</div>
    <table class="lines">
        @foreach($payslip->deductions as $line)
        <tr>
            <td class="code">{{ $line->sars_source_code_snapshot ?? '' }}</td>
            <td>{{ $line->label_snapshot }}</td>
            <td class="amount">R {{ number_format($line->amount, 2, '.', ',') }}</td>
        </tr>
        @endforeach
        <tr class="subtotal">
            <td></td>
            <td>Total Deductions</td>
            <td class="amount">R {{ number_format($payslip->total_deductions, 2, '.', ',') }}</td>
        </tr>
    </table>

    {{-- Net pay --}}
    <div class="net-pay">
        <div class="label">NET PAY</div>
        <div class="value">R {{ number_format($payslip->net_pay, 2, '.', ',') }}</div>
    </div>

    {{-- Employer contributions --}}
    @if($payslip->employerContributions->count() > 0)
    <div class="employer-contributions">
        <strong>Employer contributions (not deducted from your pay):</strong>
        <table>
            @foreach($payslip->employerContributions as $line)
            <tr>
                <td>{{ $line->label_snapshot }}</td>
                <td class="amount">R {{ number_format($line->amount, 2, '.', ',') }}</td>
            </tr>
            @endforeach
        </table>
    </div>
    @endif

    {{-- Leave balances --}}
    @if(isset($leaveBalances) && count($leaveBalances) > 0)
    <div style="margin-top: 10pt; font-size: 9pt; color: #475569;">
        <div style="font-weight: 600; font-size: 9.5pt; color: #00d4aa; text-transform: uppercase; letter-spacing: 0.5pt; border-bottom: 1px solid #e2e8f0; padding-bottom: 3pt; margin-bottom: 4pt;">
            Leave Balances (as at {{ $payslip->pay_date->format('j M Y') }})
        </div>
        <table style="width: 100%; border-collapse: collapse; font-size: 8.5pt;">
            <thead>
                <tr style="border-bottom: 1px solid #e2e8f0;">
                    <th style="text-align: left; padding: 3pt 2pt; color: #94a3b8;">Type</th>
                    <th style="text-align: right; padding: 3pt 2pt; color: #94a3b8;">Entitled</th>
                    <th style="text-align: right; padding: 3pt 2pt; color: #94a3b8;">Taken</th>
                    <th style="text-align: right; padding: 3pt 2pt; color: #94a3b8;">Pending</th>
                    <th style="text-align: right; padding: 3pt 2pt; color: #94a3b8;">Available</th>
                </tr>
            </thead>
            <tbody>
                @foreach($leaveBalances as $lb)
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 3pt 2pt; color: #1e293b;">{{ $lb['label'] }}</td>
                    <td style="text-align: right; padding: 3pt 2pt;">{{ number_format((float)$lb['entitlement'], 1) }}</td>
                    <td style="text-align: right; padding: 3pt 2pt;">{{ number_format((float)$lb['taken'], 1) }}</td>
                    <td style="text-align: right; padding: 3pt 2pt;">{{ number_format((float)$lb['pending'], 1) }}</td>
                    <td style="text-align: right; padding: 3pt 2pt; font-weight: 600;">{{ number_format((float)$lb['available'], 1) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif

    {{-- Footer --}}
    <div class="footer">
        <div>
            Generated: {{ now()->format('j M Y H:i') }}
            @if($payslip->run->isFinalised())
                | Finalised: {{ $payslip->run->finalised_at->format('j M Y H:i') }}
            @else
                | DRAFT &mdash; Not for distribution
            @endif
        </div>
        <div>Verify: {{ $verificationHash }}</div>
    </div>
</body>
</html>
