<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your deals</title>
</head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">
                    <tr>
                        <td align="center" style="padding-bottom:32px;">
                            @if(!empty($agencyName))
                                @include('emails.partials.agency-otp-header')
                            @else
                                <div style="font-size:1.75rem; font-weight:800; color:#0b2a4a;">{{ \App\Support\ClientBrand::NEUTRAL }}</div>
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">
                            <h1 style="margin:0 0 12px; font-size:1.375rem; font-weight:700; color:#111827;">Hello {{ $recipientName }}</h1>
                            <p style="margin:0 0 16px; font-size:0.9375rem; line-height:1.6; color:#374151;">
                                {{ $agencyName ?: 'Your agent' }} has set you up with a private page where you can see the deals you are working on with them,
                                and download the documents they have for you &mdash; the offer, the sale agreement, FICA papers and so on.
                            </p>
                            <p style="margin:0 0 24px; font-size:0.9375rem; line-height:1.6; color:#374151;">
                                Press the button below. We will email you a short code to make sure it is really you. Only you can see your deals.
                            </p>
                            <p style="margin:0 0 24px; text-align:center;">
                                <a href="{{ $url }}" style="display:inline-block; background:{{ $agencyColor }}; color:#ffffff; text-decoration:none; font-weight:600; padding:14px 28px; border-radius:10px;">Open my deals</a>
                            </p>
                            <p style="margin:0 0 8px; font-size:0.8125rem; line-height:1.5; color:#9ca3af;">
                                This link works for {{ $validDays }} days and is only for you &mdash; please do not forward it. If the button does not work, copy this address into your browser:<br>
                                <span style="word-break:break-all;">{{ $url }}</span>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <td align="center" style="padding-top:24px;">
                            @if(!empty($agencyName))
                                @include('emails.partials.agency-otp-footer')
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
