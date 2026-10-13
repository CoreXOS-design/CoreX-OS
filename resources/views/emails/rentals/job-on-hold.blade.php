<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #9b1c1c; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">On hold &mdash; do not attend</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        <p>The job at {{ $propertyAddress }} &mdash; <strong>{{ $jobTitle }}</strong> &mdash; is <strong>on hold</strong>.</p>
        <p data-on-hold-line><strong>Please do not attend until you hear from us.</strong></p>
        <p>We will contact you as soon as we know more.@if($agencyName) Thank you, {{ $agencyName }}.@endif</p>
    </div>
</body>
</html>
