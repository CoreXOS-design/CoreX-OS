<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Please Sign &amp; Return</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>Please find the attached document for your signature:</p>

        <div style="background-color: #f7fafc; border-left: 4px solid #1a365d; padding: 15px; margin: 20px 0;">
            <strong>{{ $documentName }}</strong>
        </div>

        @if($message)
            <div style="background-color: #fffbeb; border-left: 4px solid #d69e2e; padding: 15px; margin: 20px 0;">
                <p style="margin: 0; font-style: italic;">{{ $message }}</p>
            </div>
        @endif

        <p>Please sign all required sections and return using one of these options:</p>

        <p><strong>Option 1:</strong> Upload your signed copy here:</p>

        <div style="text-align: center; margin: 20px 0;">
            <a href="{{ $uploadUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                UPLOAD SIGNED DOCUMENT
            </a>
        </div>

        <p><strong>Option 2:</strong> Email your signed copy to:<br>
            <a href="mailto:{{ $agentFooter['email'] }}" style="color: #1a365d; font-weight: bold;">{{ $agentFooter['email'] }}</a>
        </p>

        <p style="color: #666; font-size: 13px;">
            This link expires on <strong>{{ $expiresAt->format('d M Y') }}</strong>.
            If you have any questions, simply reply to this email.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by Home Finders Coastal's document system.</p>
        <p style="margin: 5px 0 0;">If you did not expect this email, please disregard it.</p>
    </div>

</body>
</html>
