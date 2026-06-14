<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $policyName }} — acknowledgement reminder</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:Arial, Helvetica, sans-serif; color:#334155;">
    <div style="max-width:560px; margin:0 auto; padding:24px;">
        <div style="background:#0b2a4a; color:#fff; padding:18px 24px; border-radius:6px 6px 0 0;">
            <h1 style="margin:0; font-size:18px;">Policy acknowledgement required</h1>
        </div>
        <div style="background:#fff; border:1px solid #e5e7eb; border-top:none; border-radius:0 0 6px 6px; padding:24px;">
            <p style="margin:0 0 14px;">Hi {{ $recipientName }},</p>
            <p style="margin:0 0 14px; line-height:1.6;">
                You have an outstanding compliance acknowledgement for
                <strong>{{ $policyName }}</strong>. Please read and sign it as soon as possible.
            </p>
            <p style="margin:0 0 22px; line-height:1.6;">
                {{ $sentByName }} has flagged this as needing your attention.
            </p>
            <p style="margin:0 0 24px;">
                <a href="{{ $actionUrl }}"
                   style="display:inline-block; background:#00b4d8; color:#fff; text-decoration:none; font-weight:600; padding:11px 20px; border-radius:6px;">
                    Go to My Portal &rarr;
                </a>
            </p>
            <p style="margin:0; font-size:12px; color:#94a3b8; line-height:1.5;">
                Open My Portal &rarr; Compliance to start the acknowledgement. If the button does not work,
                copy this link into your browser:<br>
                <span style="color:#64748b;">{{ $actionUrl }}</span>
            </p>
        </div>
    </div>
</body>
</html>
