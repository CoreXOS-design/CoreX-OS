<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #276749; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Signed Document Returned</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $agentName }},</p>

        <p><strong>{{ $clientName }}</strong> ({{ $clientRole }}) has returned their signed copy of:</p>

        <div style="background-color: #f0fff4; border-left: 4px solid #276749; padding: 15px; margin: 20px 0;">
            <strong>{{ $documentName }}</strong>
        </div>

        <div style="background-color: #fffbeb; border-left: 4px solid #d69e2e; padding: 15px; margin: 20px 0;">
            <p style="margin: 0;"><strong>Action needed:</strong> Please review the returned document and approve it on the dashboard.</p>
            @if($nextRecipientName)
                <p style="margin: 5px 0 0;">Once approved, the document will be sent to the next person in the chain: <strong>{{ $nextRecipientName }}</strong>.</p>
            @else
                <p style="margin: 5px 0 0;">This is the last person in the chain. Approving will mark the document as complete.</p>
            @endif
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $dashboardUrl }}" style="display: inline-block; background-color: #276749; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                VIEW DASHBOARD
            </a>
        </div>
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">Home Finders Coastal — Document Management System</p>
    </div>

</body>
</html>
