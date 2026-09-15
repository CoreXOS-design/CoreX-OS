<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">Rental Application</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $contactName }},</p>

        <p>{{ $agencyName }} works on pre-approval of prospective tenants before any
           viewings take place. Please complete the rental application below —
           whichever way suits you.</p>

        <div style="text-align: center; margin: 25px 0;">
            <a href="{{ $onlineUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                COMPLETE ONLINE
            </a>
        </div>

        <p style="text-align: center; color: #666; font-size: 13px;">— or —</p>

        <div style="text-align: center; margin: 15px 0 25px;">
            <a href="{{ $downloadUrl }}" style="display: inline-block; background-color: #ffffff; color: #1a365d; padding: 12px 30px; text-decoration: none; border: 2px solid #1a365d; border-radius: 6px; font-weight: bold; font-size: 14px;">
                DOWNLOAD, COMPLETE &amp; RETURN
            </a>
        </div>

        <p style="color: #666; font-size: 13px;">
            If you download and complete this by hand, you can upload your signed form
            and supporting documents at the same online link above once it's ready —
            no need to email it separately.
        </p>

        <p style="color: #666; font-size: 13px;">
            This link expires on <strong>{{ $expiresAt }}</strong>.
            If you have any questions, please contact your agent directly.
        </p>

        @include('emails.signatures.partials.agent-footer')
    </div>

</body>
</html>
