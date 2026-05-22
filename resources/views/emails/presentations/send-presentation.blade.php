{{-- Phase 6 — presentation send email. Plain-text body wrapped in CoreX shell. --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $delivery->subject_line ?? 'Your property market analysis' }}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#0f172a;">

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;box-shadow:0 1px 3px rgba(15,23,42,0.04);">

                {{-- Header --}}
                <tr>
                    <td style="padding:24px 32px 8px 32px;border-bottom:1px solid #e2e8f0;">
                        <div style="font-size:1.25rem;font-weight:700;letter-spacing:-0.01em;color:#0f172a;">
                            {{ $agencyName ?? 'CoreX OS' }}
                        </div>
                    </td>
                </tr>

                {{-- Body (sender's message — placeholders already substituted) --}}
                <tr>
                    <td style="padding:28px 32px;font-size:14px;line-height:1.6;color:#1e293b;white-space:pre-wrap;">{!! e($bodyText) !!}</td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="padding:18px 32px;border-top:1px solid #e2e8f0;font-size:11px;line-height:1.5;color:#64748b;">
                        @if($agencyDisclaimer)
                            <div style="margin-bottom:8px;">{!! nl2br(e($agencyDisclaimer)) !!}</div>
                        @endif
                        <div>
                            This email was sent by {{ $agencyName ?? 'CoreX OS' }}@if($agentName) on behalf of {{ $agentName }}@endif.
                        </div>
                        <div style="margin-top:6px;">
                            Powered by <strong style="color:#0f172a;">CoreX OS</strong>
                        </div>
                    </td>
                </tr>

            </table>
        </td>
    </tr>
</table>
</body>
</html>
