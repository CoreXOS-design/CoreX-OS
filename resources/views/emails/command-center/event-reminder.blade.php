<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 20px;">Event Reminder</h1>
        <p style="color: #a0aec0; margin: 4px 0 0; font-size: 13px;">{{ $leadLabel }}</p>
    </div>

    <div style="padding: 24px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p style="margin: 0 0 16px;">Hi {{ $greeting }},</p>

        <p style="margin: 0 0 20px;">This is a reminder that you have an upcoming event
            <strong>{{ $leadLabel }}</strong>:</p>

        <div style="border: 1px solid #e0e0e0; border-left: 4px solid #1a365d; border-radius: 4px; padding: 16px; margin-bottom: 20px; background-color: #f7fafc;">
            <div style="font-size: 16px; font-weight: bold; color: #1a365d; margin-bottom: 6px;">
                {{ $eventTitle }}
            </div>
            <div style="font-size: 14px; color: #444; margin-bottom: 4px;">
                🕑 {{ $whenLabel }}
            </div>
            @if($propertyLabel)
                <div style="font-size: 13px; color: #555; margin-bottom: 4px;">
                    📍 {{ $propertyLabel }}
                </div>
            @endif
            @if($description)
                <div style="font-size: 13px; color: #666; margin-top: 8px; white-space: pre-line;">{{ $description }}</div>
            @endif
        </div>

        <div style="text-align: center; margin: 24px 0 8px;">
            <a href="{{ $viewUrl }}"
               style="display: inline-block; background-color: #1a365d; color: #ffffff; text-decoration: none; padding: 10px 22px; border-radius: 6px; font-size: 14px; font-weight: bold;">
                View event
            </a>
        </div>
    </div>

    <div style="padding: 16px 20px; text-align: center; color: #a0aec0; font-size: 11px;">
        <p style="margin: 0;">CoreX OS — Command Center</p>
        <p style="margin: 4px 0 0;">You are receiving this because you have email reminders enabled for your calendar events.</p>
    </div>

</body>
</html>
