<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #276749; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $isReminder ? 'Your title deed is still waiting' : 'Your title deed is ready' }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>
            The title deed for <strong>{{ $propertyAddress }}</strong> has been received from the attorneys and is
            at our office, ready for you to collect.
        </p>

        @if($pin)
            <div style="text-align: center; margin: 24px 0;">
                <p style="margin: 0 0 6px; font-size: 13px; color: #666;">Your collection PIN</p>
                <p style="margin: 0; font-size: 34px; letter-spacing: 8px; font-weight: bold; color: #1a365d;">{{ $pin }}</p>
            </div>
            <p>The deed is only released on presentation of the PIN. Please keep it private and bring it with you - it is the only way anyone collecting on your behalf can show you sent them.</p>
        @else
            <p>Please bring your ID when you collect it. If someone else is collecting for you, please let us know beforehand.</p>
        @endif

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agentFooter['agency_name'] ?? \App\Support\ClientBrand::NEUTRAL }}.</p>
    </div>

</body>
</html>
