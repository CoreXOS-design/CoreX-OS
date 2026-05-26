<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 14px; color: #1e293b; line-height: 1.7; margin: 0; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; }
        .header { border-bottom: 2px solid #0d9488; padding-bottom: 12px; margin-bottom: 20px; }
        .header h2 { margin: 0; font-size: 18px; color: #0f172a; }
        .body p { margin: 0 0 14px; }
        .ref-box { background: #f1f5f9; border-radius: 4px; padding: 12px 16px; margin: 16px 0; font-size: 13px; }
        .ref-box strong { color: #0f172a; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
        @if($isDemoMode)
        .demo-banner { background: #fef3c7; border: 1px solid #f59e0b; border-radius: 4px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px; color: #92400e; font-weight: 600; }
        @endif
    </style>
</head>
<body>
<div class="container">

    @if($isDemoMode)
    <div class="demo-banner">
        DEMO MODE — This email would be sent to the PPRA in production. Currently routed to the demo recipient for review.
    </div>
    @endif

    <div class="header">
        <h2>Formal Complaint Submission</h2>
    </div>

    <div class="body">
        <p>Dear Sir/Madam,</p>

        <p>
            Please find attached a formal complaint submission from {{ $agency->trading_name ?? $agency->name }}
            regarding a {{ $tierLabel }} matter concerning {{ $complaint->subjects_summary ?? 'the subject agencies' }}
            marketing {{ $complaint->property_address }}.
        </p>

        <div class="ref-box">
            <strong>Reference:</strong> HFC-WB-{{ $complaint->id }}<br>
            <strong>Filed:</strong> {{ $complaint->created_at->format('d F Y') }}<br>
            <strong>Approved by:</strong> {{ $complaint->approvedBy?->name ?? 'N/A' }}
            @if($complaint->approvedBy?->ffc_number)
                (FFC: {{ $complaint->approvedBy->ffc_number }})
            @endif
        </div>

        <p>
            We submit this complaint in good faith based on the evidence catalogued in the attached PDF.
            We are available for any further information or follow-up at
            {{ $agency->whistleblow_compliance_officer_email ?? $agency->email }}.
        </p>

        <p>Yours faithfully,<br>{{ $agency->trading_name ?? $agency->name }}</p>
    </div>

    <div class="footer">
        {{ $agency->trading_name ?? $agency->name }}
        @if($agency->phone) | {{ $agency->phone }} @endif
        @if($agency->email) | {{ $agency->email }} @endif
    </div>

</div>
</body>
</html>
