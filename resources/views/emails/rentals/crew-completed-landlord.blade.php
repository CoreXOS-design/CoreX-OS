<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work completed: {{ $title }}</title>
    <style>
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; font-size: 15px; color: #1e293b; line-height: 1.6; background: #f8fafc; }
        .wrapper { max-width: 560px; margin: 0 auto; background: #ffffff; }
        .header { background: #0f172a; padding: 22px 28px; color: #ffffff; font-size: 17px; font-weight: 700; }
        .body { padding: 28px; }
        .job { background: #f1f5f9; border-radius: 6px; padding: 14px 18px; margin: 16px 0; }
        .muted { color: #64748b; font-size: 13px; }
        .footer { background: #f1f5f9; padding: 16px 28px; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">{{ $agencyName }}</div>
    <div class="body">
        <p>Dear {{ $landlordName }},</p>
        <p>The maintenance crew has reported the following work as completed:</p>
        <div class="job">
            <strong>{{ $title }}</strong>
            @if($address)<br>{{ $address }}@endif
            <br>Signed by {{ $signedByName }} on {{ $completedOn }}
        </div>
        <p class="muted">We will now check the work. You will hear from us if anything further is needed.</p>
    </div>
    <div class="footer">
        {{ $footer['name'] ?? $agencyName }}@if(!empty($footer['phone'])) · {{ $footer['phone'] }}@endif @if(!empty($footer['email']))· {{ $footer['email'] }}@endif
    </div>
</div>
</body>
</html>
