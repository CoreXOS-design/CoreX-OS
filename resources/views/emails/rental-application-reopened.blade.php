<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Rental Application — Update Needed</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $contactName }},</p>

        <p>Your rental application with {{ $agencyName }} needs a quick update before we can carry on with it:</p>

        <div style="background-color: #f7fafc; border-left: 4px solid #1a365d; padding: 12px 16px; margin: 20px 0; font-style: italic;">
            {{ $note }}
        </div>

        <p>Your previous answers are still there — you only need to fix what's
           mentioned above. You'll need to sign again once you've made the change.</p>

        <div style="text-align: center; margin: 25px 0;">
            <a href="{{ $onlineUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                UPDATE MY APPLICATION
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            This link expires on <strong>{{ $expiresAt }}</strong>.
            If you have any questions, please contact your agent directly.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

</body>
</html>
