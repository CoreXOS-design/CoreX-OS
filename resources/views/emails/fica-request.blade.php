<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">FICA Verification Required</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $contactName }},</p>

        <p>As part of our compliance obligations under the Financial Intelligence Centre Act (FICA), we require you to complete a verification form before we can proceed with your transaction.</p>

        <div style="background-color: #f7fafc; border-left: 4px solid #1a365d; padding: 15px; margin: 20px 0;">
            <strong>What you will need:</strong>
            <ul style="margin: 10px 0 0; padding-left: 20px;">
                <li>Your South African ID or passport</li>
                <li>Proof of residential address (less than 3 months old)</li>
                <li>Proof of income or bank statement</li>
            </ul>
        </div>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $ficaUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                COMPLETE FICA FORM
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            This link expires on <strong>{{ $expiresAt }}</strong>.
            If you have any questions, please contact your agent directly.
        </p>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 25px 0;">

        <p style="color: #666; font-size: 13px; margin: 0;">
            Kind regards,<br>
            <strong>{{ $agentName }}</strong><br>
            {{ $agencyName }}
        </p>
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">This email was sent by {{ $agencyName }}'s compliance system.</p>
        <p style="margin: 5px 0 0;">If you did not expect this email, please disregard it.</p>
    </div>

</body>
</html>
