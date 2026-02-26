<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #c05621; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Follow-Up Needed</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $agentName }},</p>

        <p><strong>{{ $signerName }}</strong> ({{ $signerEmail }}) was sent <strong>{{ $documentName }}</strong> for signature <strong>{{ $daysSinceSent }} days ago</strong> and hasn't completed it yet.</p>

        <div style="background-color: #fffbeb; border-left: 4px solid #c05621; padding: 15px; margin: 20px 0;">
            <table style="width: 100%; font-size: 14px; border-collapse: collapse;">
                <tr>
                    <td style="padding: 4px 0; color: #666;">Signer:</td>
                    <td style="padding: 4px 0; font-weight: bold;">{{ $signerName }}</td>
                </tr>
                <tr>
                    <td style="padding: 4px 0; color: #666;">Document:</td>
                    <td style="padding: 4px 0; font-weight: bold;">{{ $documentName }}</td>
                </tr>
                <tr>
                    <td style="padding: 4px 0; color: #666;">Status:</td>
                    <td style="padding: 4px 0;">
                        @if($signerStatus === 'viewed')
                            <span style="color: #2563eb;">Viewed but not signed</span>
                        @elseif($signerStatus === 'partially_signed')
                            <span style="color: #c05621;">Partially signed</span>
                        @else
                            <span style="color: #9b2c2c;">Not yet viewed</span>
                        @endif
                    </td>
                </tr>
                <tr>
                    <td style="padding: 4px 0; color: #666;">Days waiting:</td>
                    <td style="padding: 4px 0; font-weight: bold; color: #c05621;">{{ $daysSinceSent }} days</td>
                </tr>
            </table>
        </div>

        <p>You may want to follow up with them directly by phone or WhatsApp.</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $dashboardUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                VIEW DASHBOARD
            </a>
        </div>
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This is an internal notification from Home Finders Coastal's document signing system.</p>
    </div>

</body>
</html>
