<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $subjectLine }}</title>
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

                            <p style="margin:0 0 4px; font-size:0.75rem; font-weight:600; color:#9ca3af; text-transform:uppercase; letter-spacing:0.05em;">
                                Message from CoreX OS
                            </p>

                            <h1 style="margin:0 0 16px; font-size:1.375rem; font-weight:700; color:#111827;">
                                {{ $subjectLine }}
                            </h1>

                            <p style="margin:0 0 16px; font-size:0.9375rem; color:#111827;">
                                Hi {{ $recipientName }},
                            </p>

                            {{-- Body. Escaped, ALWAYS — never raw HTML.

                                 The author is a trusted System Owner, but this renders in
                                 every recipient's inbox including HTML-rendering webmail.
                                 e() escapes first, then nl2br adds line breaks, so a typed
                                 <script> appears as visible text (spec §6). --}}
                            <div style="font-size:0.9375rem; line-height:1.7; color:#374151;">
                                {!! nl2br(e($messageBody)) !!}
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
