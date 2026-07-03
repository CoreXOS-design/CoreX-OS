<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #0b2a4a; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">{{ $documentTitle }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>Please find attached <strong>{{ $documentTitle }}</strong>
            @if($propertyAddress) for <strong>{{ $propertyAddress }}</strong>@endif
            (reference {{ $dealReference }}).</p>

        @if($messageLine)
            <p>{{ $messageLine }}</p>
        @endif

        <div style="background-color: #f0fdf4; border-left: 4px solid #16a34a; padding: 14px; margin: 20px 0; font-size: 13px;">
            <p style="margin: 0;">The document is attached to this email as a PDF. Please reply to this email if
                you have any questions.</p>
        </div>
    </div>

    @include('emails.signatures.partials.agent-footer', ['agentFooter' => $agentFooter])
</body>
</html>
