<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #0b2a4a; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Document Cancelled</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $signerName }},</p>

        <p>The document <strong>{{ $documentName }}</strong> has been cancelled by {{ $agentName }}.</p>

        <div style="background-color: #fef2f2; border-left: 4px solid #ef4444; padding: 15px; margin: 20px 0;">
            <p style="margin: 0 0 5px 0; font-weight: 600; color: #991b1b;">Reason for cancellation:</p>
            <p style="margin: 0; color: #7f1d1d;">{{ $cancellationReason }}</p>
        </div>

        <p style="color: #666; font-size: 13px;">Any previous signing link for this document is no longer active. You do not need to take any further action.</p>

        <p style="color: #666; font-size: 13px;">If you have questions, please contact the agent directly.</p>

        @include('emails.signatures.partials.agent-footer')
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agentFooter['agency_name'] ?? config('app.name') }}'s document signing system.</p>
    </div>

</body>
</html>
