<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome to CoreX — set up your agency</title>
</head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">

                    {{-- Logo / Branding --}}
                    <tr>
                        <td align="center" style="padding-bottom:32px;">
                            <div style="font-size:1.75rem; font-weight:800; letter-spacing:-0.04em; color:#0b2a4a; line-height:1;">
                                CoreX <span style="color:#00b4d8;">Os</span>
                            </div>
                        </td>
                    </tr>

                    {{-- Card --}}
                    <tr>
                        <td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">

                            <h1 style="margin:0 0 8px; font-size:1.375rem; font-weight:700; color:#111827;">
                                Let's set up {{ $agencyName }}
                            </h1>
                            <p style="margin:0 0 24px; font-size:0.9375rem; line-height:1.6; color:#6b7280;">
                                Your agency has been created on <strong style="color:#111827;">CoreX OS</strong>.
                                Before you dive in, we'll walk you through the key settings — commission,
                                listings, presentations, compliance and more — in plain English, one step at
                                a time. Sensible defaults are already filled in, so you can review and adjust
                                only what you need.
                            </p>
                            <p style="margin:0 0 24px; font-size:0.9375rem; line-height:1.6; color:#6b7280;">
                                Sign in with your CoreX Admin details to begin. You can pause and resume any
                                time — your progress is saved.
                            </p>

                            {{-- CTA Button --}}
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 24px;">
                                <tr>
                                    <td align="center" style="background:#00b4d8; border-radius:8px;">
                                        <a href="{{ $setupUrl }}" target="_blank"
                                           style="display:inline-block; padding:14px 36px; color:#ffffff; font-size:0.9375rem; font-weight:600; text-decoration:none; letter-spacing:0.01em;">
                                            Start Agency Setup
                                        </a>
                                    </td>
                                </tr>
                            </table>

                            <p style="margin:0 0 16px; font-size:0.8125rem; line-height:1.5; color:#9ca3af;">
                                This link stays valid for <strong>30 days</strong>. If it expires, your CoreX
                                administrator can re-send it from the Agency Setup Progress page.
                            </p>

                            {{-- Fallback URL --}}
                            <div style="background:#f8fafc; border:1px solid #e5e7eb; border-radius:8px; padding:12px 16px; margin-top:16px;">
                                <p style="margin:0 0 4px; font-size:0.6875rem; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em;">
                                    Or copy and paste this link:
                                </p>
                                <p style="margin:0; font-size:0.75rem; color:#6b7280; word-break:break-all; line-height:1.4;">
                                    {{ $setupUrl }}
                                </p>
                            </div>

                        </td>
                    </tr>

                    {{-- Footer --}}
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
