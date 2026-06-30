<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $heading ?? 'Your CoreX verification code' }}</title>
</head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">

                    <tr>
                        <td align="center" style="padding-bottom:32px;">
                            <div style="font-size:1.75rem; font-weight:800; letter-spacing:-0.04em; color:#0b2a4a; line-height:1;">
                                CoreX <span style="color:#00b4d8;">Os</span>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">
                            <h1 style="margin:0 0 8px; font-size:1.375rem; font-weight:700; color:#111827;">
                                {{ $heading ?? 'Your verification code' }}
                            </h1>
                            <p style="margin:0 0 24px; font-size:0.9375rem; line-height:1.6; color:#6b7280;">
                                Enter this code to continue. It will expire in
                                <strong style="color:#111827;">{{ $expiresMinutes }} minutes</strong>.
                            </p>

                            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:12px; padding:24px; text-align:center; margin:0 0 24px;">
                                <div style="font-family:'JetBrains Mono', 'SFMono-Regular', Menlo, Monaco, Consolas, monospace; font-size:2.25rem; font-weight:700; color:#0b2a4a; letter-spacing:0.5em; padding-left:0.5em;">
                                    {{ $code }}
                                </div>
                            </div>

                            <p style="margin:0 0 8px; font-size:0.8125rem; line-height:1.5; color:#9ca3af;">
                                If you did not request this code, you can safely ignore this email — your account stays secure.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td align="center" style="padding-top:24px;">
                            <p style="margin:0; font-size:0.6875rem; color:#9ca3af;">
                                &copy; {{ date('Y') }} CoreX OS. All rights reserved.
                            </p>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>

</body>
</html>
