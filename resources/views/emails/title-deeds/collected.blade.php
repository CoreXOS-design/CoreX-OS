<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #276749; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Your title deed was collected</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $recipientName }},</p>

        <p>The title deed for <strong>{{ $propertyAddress }}</strong> has been collected from our office.</p>

        <table cellpadding="0" cellspacing="0" border="0" style="font-size: 14px; margin: 16px 0;">
            <tr><td style="padding: 3px 16px 3px 0; color: #666;">Collected by</td><td style="padding: 3px 0;"><strong>{{ $collectorName }}</strong></td></tr>
            <tr><td style="padding: 3px 16px 3px 0; color: #666;">When</td><td style="padding: 3px 0;"><strong>{{ $collectedOn }}</strong></td></tr>
            <tr><td style="padding: 3px 16px 3px 0; color: #666;">Verified by</td><td style="padding: 3px 0;"><strong>{{ $methodLabel }}</strong></td></tr>
        </table>

        <p>If this was not you, or you were not expecting this, please contact us straight away.</p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agentFooter['agency_name'] ?? config('app.name') }}.</p>
    </div>

</body>
</html>
