<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">On Record</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $tenantName }},</p>

        <p>{{ $agencyName }} has logged the following against {{ $propertyAddress }}:</p>
        <p><strong>{{ $workOrder->title }}</strong></p>
        <p>{{ $workOrder->description }}</p>

        <p style="color: #666; font-size: 13px;">
            If you have any questions, please contact {{ $agencyName }} directly.
        </p>
    </div>

</body>
</html>
