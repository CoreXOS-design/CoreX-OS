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
        .cta { display: inline-block; background: #0d9488; color: #ffffff !important; text-decoration: none; padding: 10px 20px; border-radius: 4px; font-weight: 600; font-size: 14px; margin: 8px 0 16px; }
        .footer { margin-top: 24px; padding-top: 12px; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
    </style>
</head>
<body>
<div class="container">

    <div class="header">
        <h2>New Compliance Report Filed</h2>
    </div>

    <div class="body">
        <p>Dear Compliance Officer,</p>

        <p>
            <strong>{{ $reporter->name }}</strong> has filed a Tier {{ str_replace('tier_', '', $complaint->tier) }} compliance report
            regarding <strong>{{ $complaint->property_address }}</strong>
            @if($complaint->subjects_summary)
                concerning {{ $complaint->subjects_summary }}
            @endif
            . It is now awaiting approval.
        </p>

        <div class="ref-box">
            <strong>Reference:</strong> CDX-WB-{{ $complaint->id }}<br>
            <strong>Reported by:</strong> {{ $reporter->name }}<br>
            <strong>Filed:</strong> {{ $complaint->created_at?->format('d F Y H:i') }}<br>
            @if($complaint->seller_statement)
                <strong>Seller statement:</strong> {{ \Illuminate\Support\Str::limit($complaint->seller_statement, 200) }}
            @endif
        </div>

        <p>
            <a href="{{ $reviewUrl }}" class="cta">Review the report</a>
        </p>

        <p>You can approve, reject, or request changes on the report from that link.</p>
    </div>

    <div class="footer">
        {{ $agentFooter['agency_name'] ?? ($agency->trading_name ?? $agency->name) }}
        @if(!empty($agentFooter['phone'])) &bull; {{ $agentFooter['phone'] }} @endif
        @if(!empty($agentFooter['email'])) &bull; {{ $agentFooter['email'] }} @endif
    </div>

</div>
</body>
</html>
