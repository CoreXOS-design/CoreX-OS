<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $changed ? 'Appointment changed' : 'Appointment set' }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Dear {{ $tenantName }},</p>

        <p>The repair for <strong>{{ $title }}</strong> at {{ $propertyAddress }} has {{ $changed ? 'a new appointment' : 'an appointment' }}:</p>
        <p style="font-size: 18px;"><strong>{{ $when }}</strong></p>
        <p>Who is doing the work: {{ $who }}.</p>
        @if($note)
            <p>{{ $note }}</p>
        @endif
        <p>Please make sure someone can give access at that time. If it does not suit you, contact {{ $agencyName }} as soon as you can. You can follow the repair on your <a href="{{ $portalUrl }}">portal</a>.</p>
    </div>

</body>
</html>
