<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">A Little More Needed</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $contactName }},</p>

        <p>{{ $agencyName }} is reviewing your rental application and needs a little more from you before it can go ahead:</p>

        <div style="background-color: #fffbeb; border: 1px solid #fcd34d; border-radius: 6px; padding: 16px; margin: 20px 0; white-space: pre-wrap;">{{ $note }}</div>

        <div style="text-align: center; margin: 25px 0;">
            <a href="{{ $onlineUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                GO TO YOUR APPLICATION
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            Use the same link you used before — you can add documents or update your details there.
        </p>

        <p style="color: #666; font-size: 13px;">
            If you have any questions, please contact {{ $agencyName }} directly.
        </p>
    </div>

</body>
</html>
