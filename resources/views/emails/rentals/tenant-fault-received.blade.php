<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">We have your fault report</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $recipientName }},</p>
        <p>Thank you. At {{ $propertyAddress }}, we have your fault report "{{ $faultReport->title }}" and your agent has been told.</p>
        <p>You can see where it is, step by step, on your <a href="{{ $portalUrl }}">portal</a>. We will let you know as it moves.</p>
    </div>
</body>
</html>
