<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">Board approval of the RMCP</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $signature->member_name }},</p>

        <p>{{ $agencyName }} has a new Risk Management and Compliance Programme (RMCP), version {{ $versionNumber }}. The law asks the board to approve it in writing.</p>

        <p>Please open the letter, read it, and sign it. It takes about two minutes. We will email you a six-digit code to confirm it is you.</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="{{ $url }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                OPEN AND SIGN THE LETTER
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            The programme takes effect on <strong>{{ $letter->effective_from->format('d F Y') }}</strong>.
            This link is only for you. Please do not forward it.
        </p>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 25px 0;">

        <p style="color: #666; font-size: 13px; margin: 0;">
            Kind regards,<br>
            <strong>{{ $letter->sender?->name ?? $agencyName }}</strong><br>
            {{ $agencyName }}
        </p>
    </div>

    <div style="text-align: center; padding: 15px; color: #999; font-size: 11px;">
        <p style="margin: 0;">If you did not expect this email, please ignore it.</p>
    </div>
</body>
</html>
