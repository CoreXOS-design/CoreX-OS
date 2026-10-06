<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"></head>
<body style="font-family: Arial, Helvetica, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;">
    <div style="background-color: #1a365d; padding: 20px; text-align: center; border-radius: 8px 8px 0 0;">
        <h1 style="color: #ffffff; margin: 0; font-size: 22px;">{{ $agencyName }}</h1>
    </div>
    <div style="padding: 30px 20px; background-color: #ffffff; border: 1px solid #e0e0e0; border-top: none;">
        <p>Hi {{ $crewName }},</p>
        <p>This is your team's jobs page. It lists the jobs booked for you, where they are, when, and what to load — open a job to tick off tasks, add photos and mark the work completed. No login is needed.</p>
        <p style="text-align: center; margin: 28px 0;">
            <a href="{{ $url }}" style="background-color: #00b4d8; color: #ffffff; padding: 14px 26px; text-decoration: none; border-radius: 8px; font-weight: bold; display: inline-block;">Open your jobs page</a>
        </p>
        <p style="font-size: 13px; color: #666;">Or paste this link into your phone's browser:<br><a href="{{ $url }}" style="color: #0b6e99; word-break: break-all;">{{ $url }}</a></p>
        <p style="font-size: 13px; color: #666;">
            Keep this link to yourselves — anyone who has it can open your team's jobs.
            @if($expiryText) {{ $expiryText }} @else It stays valid until the office replaces it. @endif
            If it ever stops working, ask the office for a new one.
        </p>
        <p>Thanks,<br>{{ $senderName }}<br>{{ $agencyName }}</p>
    </div>
</body>
</html>
