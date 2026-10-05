<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>PPRA Confirmation of Employment — {{ $agent->name }}</title>
    <style>
        {{-- Header/footer shape borrowed from
             resources/views/admin/ppra-inspection-pack/letterhead-sample.blade.php
             (Inspection Pack letterhead pattern) — same layout, same fields,
             this module's own body content in .body-area. --}}
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1e293b; }
        .page { padding: 40px 48px; min-height: 700px; position: relative; }
        .header { display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #0d9488; padding-bottom: 16px; margin-bottom: 24px; }
        .header img.logo { max-height: 70px; }
        .header .agency-name { font-size: 18px; font-weight: 700; color: #0f172a; }
        .header .agency-legal { font-size: 11px; color: #64748b; }
        .body-area { min-height: 460px; padding-bottom: 90px; }
        .footer { position: absolute; bottom: 40px; left: 48px; right: 48px; border-top: 1px solid #e2e8f0; padding-top: 12px; font-size: 10px; color: #94a3b8; }
        .footer .ppra { font-weight: 700; color: #334155; }

        .letter-date { margin-bottom: 18px; }
        .ppra-address { white-space: pre-line; margin-bottom: 18px; line-height: 1.5; }
        .letter-re { font-weight: 700; text-transform: uppercase; margin-bottom: 18px; }
        .letter-body { line-height: 1.6; margin-bottom: 22px; }
        .mentor-heading { font-weight: 700; margin-bottom: 8px; }
        table.mentor-table { width: 100%; border-collapse: collapse; margin-bottom: 28px; }
        table.mentor-table th, table.mentor-table td { border: 1px solid #cbd5e1; padding: 6px 10px; text-align: left; font-size: 11px; }
        table.mentor-table th { background: #f1f5f9; font-weight: 700; }

        .signature-blocks { display: flex; justify-content: space-between; gap: 32px; margin-top: 36px; }
        .signature-block { width: 46%; }
        .signature-label { font-weight: 700; margin-bottom: 28px; }
        .signature-line { border-bottom: 1px solid #1e293b; height: 42px; display: flex; align-items: flex-end; margin-bottom: 4px; }
        .signature-line img { max-height: 40px; max-width: 100%; }
        .signature-name { font-weight: 700; font-size: 11px; }
        .signature-title { font-size: 10px; color: #64748b; }
        .signature-meta { font-size: 9px; color: #94a3b8; margin-top: 2px; }
    </style>
</head>
<body>
<div class="page">
    <div class="header">
        <div>
            <div class="agency-name">{{ $agency->trading_name ?? $agency->name }}</div>
            @if($agency->trading_name && $agency->trading_name !== $agency->name)
                <div class="agency-legal">{{ $agency->name }}</div>
            @endif
        </div>
        @if($agency->logo_path)
            <img class="logo" src="{{ public_path('storage/' . $agency->logo_path) }}" alt="Logo">
        @endif
    </div>

    <div class="body-area">
        <div class="letter-date">{{ $letterDate }}</div>

        <div class="ppra-address">{{ $ppraAddressBlock }}</div>

        <div class="letter-re">RE: Confirmation of Employment for a {{ $designation }}</div>

        <div class="letter-body">
            This serves to confirm that ({{ $agent->name }}), ID number ({{ $agentIdNumber }})
            seven digit reference number ({{ $agentFfcNumber }}) is in the employ of
            ({{ $agencyLegalName }} t/a {{ $agencyTradingName }}) ({{ $agencyPpraNumber }}).
        </div>

        <div class="mentor-heading">Mentor's details:</div>
        <table class="mentor-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Surname</th>
                    <th>Seven digit reference number</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td>{{ $principalFirstName }}</td>
                    <td>{{ $principalSurname }}</td>
                    <td>{{ $principalFfcNumber }}</td>
                </tr>
            </tbody>
        </table>

        <div>Yours faithfully,</div>

        <div class="signature-blocks">
            <div class="signature-block">
                <div class="signature-line">
                    @if($principalSignatureImage)
                        <img src="{{ $principalSignatureImage }}" alt="Principal signature">
                    @endif
                </div>
                <div class="signature-name">{{ $principal?->name ?? '' }}</div>
                <div class="signature-title">Principal Estate Agent</div>
                @if($principalSignedAt)
                    <div class="signature-meta">Signed {{ $principalSignedAt }}</div>
                @endif
            </div>
            <div class="signature-block">
                <div class="mentor-heading" style="margin-bottom:0;">Employment accepted by</div>
                <div class="signature-line">
                    @if($agentSignatureImage)
                        <img src="{{ $agentSignatureImage }}" alt="Agent signature">
                    @endif
                </div>
                <div class="signature-name">{{ $agent->name }}</div>
                <div class="signature-title">{{ $designation }}</div>
                @if($agentSignedAt)
                    <div class="signature-meta">Signed {{ $agentSignedAt }}</div>
                @endif
            </div>
        </div>
    </div>

    <div class="footer">
        <div>
            {{ $agency->trading_name ?? $agency->name }}
            @if($agency->address) &bull; {{ $agency->address }} @endif
        </div>
        <div>
            @if($agency->phone){{ $agency->phone }} @endif
            @if($agency->email) &bull; {{ $agency->email }} @endif
            @if($agencyPpraNumber) &bull; <span class="ppra">PPRA Reg. No: {{ $agencyPpraNumber }}</span> @endif
        </div>
    </div>
</div>
</body>
</html>
