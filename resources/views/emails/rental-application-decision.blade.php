<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">

    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $headline }}</h1>
    </div>

    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $agentName }},</p>

        @if($decision === 'approved')
            <p><strong>{{ $contactName }}</strong>'s rental application has been approved{{ $isOverride ? ' (this overrides an earlier decision)' : '' }}, for a monthly amount of R{{ number_format((float) $application->approved_rental_amount, 2) }}.</p>
        @elseif($decision === 'declined')
            <p><strong>{{ $contactName }}</strong>'s rental application has been declined{{ $isOverride ? ' (this overrides an earlier decision)' : '' }}.</p>
        @else
            <p>More information has been requested on <strong>{{ $contactName }}</strong>'s rental application — it's back with you to action.</p>
        @endif

        @if($reason)
            <div style="background-color: #f4f6fb; border-radius: 6px; padding: 16px; margin: 20px 0; white-space: pre-wrap;">{{ $reason }}</div>
        @endif

        <div style="text-align: center; margin: 25px 0;">
            <a href="{{ $reviewUrl }}" style="display: inline-block; background-color: #1a365d; color: #ffffff; padding: 14px 40px; text-decoration: none; border-radius: 6px; font-weight: bold; font-size: 16px;">
                VIEW APPLICATION
            </a>
        </div>
    </div>

</body>
</html>
