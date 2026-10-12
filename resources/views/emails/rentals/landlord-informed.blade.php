<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">For your information</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        <p>At {{ $propertyAddress }}:</p>
        <p><strong>{{ $title }}</strong></p>
        <p>{{ $arrangedText }} You do not need to do anything &mdash; we are telling you so you always know what has been done at your property. We will tell you again, with the final amount, when the work is completed.</p>
        <p style="text-align: center; margin: 24px 0;"><a href="{{ $portalUrl }}" style="background-color: #1a365d; color: #ffffff; padding: 12px 24px; border-radius: 6px; text-decoration: none; font-weight: bold; display: inline-block;">See the details</a></p>
        <p style="color: #666; font-size: 13px;">If you have a question about it you can ask your agent from there. You will be asked to sign in first.</p>
    </div>
</body>
</html>
