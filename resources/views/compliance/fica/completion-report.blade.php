<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>FICA Completion Report</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; font-size: 12px; color: #1e293b; line-height: 1.5; padding: 40px; max-width: 210mm; margin: 0 auto; background: #fff; }
        @media print { body { padding: 20mm; } @page { size: A4; margin: 15mm; } }
        .header { text-align: center; border-bottom: 3px solid #0d9488; padding-bottom: 15px; margin-bottom: 25px; }
        .header img { max-height: 50px; margin-bottom: 8px; }
        .header h1 { font-size: 20px; font-weight: 700; color: #1e293b; margin: 4px 0 2px; }
        .header .subtitle { font-size: 11px; color: #64748b; }
        .section { margin-bottom: 22px; }
        .section-title { font-size: 13px; font-weight: 700; color: #1e293b; border-bottom: 2px solid #0d9488; padding-bottom: 4px; margin-bottom: 10px; }
        .qa-row { display: grid; grid-template-columns: 1fr 1fr; gap: 4px 20px; padding: 6px 0; border-bottom: 1px solid #f1f5f9; }
        .qa-label { font-size: 10.5px; color: #64748b; }
        .qa-value { font-size: 12px; color: #1e293b; font-weight: 500; word-break: break-word; }
        .signature-block { margin-top: 10px; }
        .signature-block img { max-height: 90px; max-width: 260px; object-fit: contain; border: 1px solid #e2e8f0; border-radius: 4px; padding: 6px; background: #fff; display: block; }
        .signature-caption { font-size: 10px; color: #94a3b8; margin-top: 4px; }
        .approver-box { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 6px; padding: 14px 16px; }
        .approver-name { font-size: 13px; font-weight: 700; color: #1e293b; }
        .approver-titles { font-size: 10.5px; color: #0d9488; font-weight: 600; margin-top: 1px; }
        .approver-date { font-size: 10.5px; color: #94a3b8; margin-top: 1px; }
        .notes-box { margin-top: 8px; font-size: 11px; color: #475569; background: #fff; border: 1px solid #e2e8f0; border-radius: 4px; padding: 8px 10px; }
        .footer { margin-top: 30px; padding-top: 15px; border-top: 1px solid #e2e8f0; font-size: 9px; color: #94a3b8; text-align: center; }
        .no-print { display: flex; gap: 10px; margin-bottom: 20px; }
        .no-print button { padding: 8px 20px; font-size: 13px; font-weight: 600; border: none; border-radius: 4px; cursor: pointer; }
        .no-print .print-btn { background: #1e293b; color: #fff; }
        .no-print .back-btn { background: #f1f5f9; color: #334155; border: 1px solid #e2e8f0; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body>
    @php
        $agency = $submission->agency;
        $recipientName = $submission->contact?->full_name
            ?? collect($sections)->firstWhere('title', 'Person Completing This Form')['rows'][0]['value']
            ?? 'the client';
    @endphp

    <div class="no-print">
        <a href="{{ route('compliance.fica.completion-report.pdf', $submission) }}" class="print-btn" style="text-decoration:none; display:inline-block;">Download PDF</a>
        <button class="back-btn" onclick="window.print()">Print (browser)</button>
        <button class="back-btn" onclick="history.back()">Back</button>
    </div>

    <div class="header">
        @if($agency && $agency->logo_path)
            <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}">
        @endif
        <h1>FICA Completion Report</h1>
        <div class="subtitle">{{ $recipientName }} — what was selected, answered and approved</div>
    </div>

    @foreach($sections as $section)
        <div class="section">
            <div class="section-title">{{ $section['title'] }}</div>
            @foreach($section['rows'] as $row)
                <div class="qa-row">
                    <div class="qa-label">{{ $row['label'] }}</div>
                    <div class="qa-value">{{ $row['value'] }}</div>
                </div>
            @endforeach
        </div>
    @endforeach

    @if($signature)
    <div class="section">
        <div class="section-title">Client Signature</div>
        <div class="signature-block">
            <img src="{{ $signature }}" alt="Client signature">
            <div class="signature-caption">Signed by {{ $recipientName }}</div>
        </div>
    </div>
    @endif

    @if($agent)
    <div class="section">
        <div class="section-title">Agent</div>
        <div class="approver-box">
            <div class="approver-name">{{ $agent['name'] }}</div>
            @if($agent['verified_at'])<div class="approver-date">{{ $agent['verified_at'] }}</div>@endif
            <div style="margin-top: 10px;">
                @foreach($agent['ticks'] as $tick)
                    <div class="qa-row" style="border-bottom-color: #e2e8f0;">
                        <div class="qa-label">{{ $tick['label'] }}</div>
                        <div class="qa-value">{{ $tick['value'] }}</div>
                    </div>
                @endforeach
            </div>
            @if($agent['notes'])<div class="notes-box">{{ $agent['notes'] }}</div>@endif
        </div>
    </div>
    @endif

    @if($co)
    <div class="section">
        <div class="section-title">Approval</div>
        <div class="approver-box">
            <div class="approver-name">{{ $co['name'] }}</div>
            @if(!empty($co['titles']))
                <div class="approver-titles">{{ implode(' · ', $co['titles']) }}</div>
            @endif
            @if($co['verified_at'])<div class="approver-date">{{ $co['verified_at'] }}</div>@endif
            <div style="margin-top: 10px;">
                @foreach($co['ticks'] as $tick)
                    <div class="qa-row" style="border-bottom-color: #e2e8f0;">
                        <div class="qa-label">{{ $tick['label'] }}</div>
                        <div class="qa-value">{{ $tick['value'] }}</div>
                    </div>
                @endforeach
            </div>
            @if($co['notes'])<div class="notes-box">{{ $co['notes'] }}</div>@endif
            @if($co['signature'])
            <div class="signature-block">
                <img src="{{ $co['signature'] }}" alt="Approver signature">
                <div class="signature-caption">Signed by {{ $co['name'] }}</div>
            </div>
            @endif
        </div>
    </div>
    @endif

    <div class="footer">
        <p>FICA Completion Report for {{ $recipientName }} — {{ $agency->name ?? 'Home Finders Coastal' }}</p>
    </div>
</body>
</html>
