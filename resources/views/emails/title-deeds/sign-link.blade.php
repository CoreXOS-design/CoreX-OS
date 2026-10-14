<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #276749; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Please sign to collect the deed</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>
            You are collecting the title deed for <strong>{{ $propertyAddress }}</strong> from {{ $agencyName }}.
            Open the link on your phone, check the details, sign with your finger and press Confirm.
        </p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $signUrl }}" style="display: inline-block; background-color: #276749; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                OPEN AND SIGN
            </a>
        </div>

        <p style="font-size: 13px; color: #666;">
            This link is personal to you and works once. It stops working at {{ $expiresOn }}.
            If the button does not work, copy this address into your browser:<br>
            <span style="word-break: break-all;">{{ $signUrl }}</span>
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agentFooter['agency_name'] ?? \App\Support\ClientBrand::NEUTRAL }}.</p>
    </div>

</body>
</html>
