<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>New documents</title></head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;">
        <tr><td align="center">
            <table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">
                <tr><td align="center" style="padding-bottom:32px;">
                    @if(!empty($agencyName)) @include('emails.partials.agency-otp-header') @else <div style="font-size:1.75rem; font-weight:800; color:#0b2a4a;">{{ \App\Support\ClientBrand::NEUTRAL }}</div> @endif
                </td></tr>
                <tr><td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">
                    <h1 style="margin:0 0 12px; font-size:1.25rem; font-weight:700; color:#111827;">Hello {{ $recipientName }}</h1>
                    <p style="margin:0 0 14px; font-size:0.9375rem; line-height:1.6; color:#374151;">
                        {{ $agencyName ?: 'Your agent' }} has added new documents for <strong>{{ $address }}</strong> that you can download:
                    </p>
                    <ul style="margin:0 0 20px; padding-left:20px; font-size:0.9375rem; line-height:1.7; color:#374151;">
                        @foreach($kinds as $label => $count)<li>{{ $label }}@if($count > 1) ({{ $count }})@endif</li>@endforeach
                    </ul>
                    <p style="margin:0 0 24px; text-align:center;">
                        <a href="{{ $url }}" style="display:inline-block; background:{{ $agencyColor }}; color:#ffffff; text-decoration:none; font-weight:600; padding:14px 28px; border-radius:10px;">Open this deal</a>
                    </p>
                    <p style="margin:0; font-size:0.8125rem; line-height:1.5; color:#9ca3af;">
                        We never attach documents to this email. You will be asked for a code we email you before anything opens. You get at most one of these a day for each deal.
                    </p>
                </td></tr>
                <tr><td align="center" style="padding-top:24px;">@if(!empty($agencyName)) @include('emails.partials.agency-otp-footer') @endif</td></tr>
            </table>
        </td></tr>
    </table>
</body>
</html>
