<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Work reported as not complete: {{ $title }}</title>
    <style>
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; font-size: 15px; color: #1e293b; line-height: 1.6; background: #f8fafc; }
        .wrapper { max-width: 560px; margin: 0 auto; background: #ffffff; }
        .header { background: #0f172a; padding: 22px 28px; color: #ffffff; font-size: 17px; font-weight: 700; }
        .body { padding: 28px; }
        .job { background: #f1f5f9; border-radius: 6px; padding: 14px 18px; margin: 16px 0; }
        .btn { display:inline-block; background:#0f172a; color:#ffffff !important; text-decoration:none; padding:12px 22px; border-radius:6px; font-weight:600; }
        .quote { background:#fff7ed; border-left:4px solid #f97316; padding:12px 16px; margin:16px 0; white-space:pre-line; }
        .muted { color: #64748b; font-size: 13px; }
        .footer { background: #f1f5f9; padding: 16px 28px; font-size: 12px; color: #64748b; }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="header">{{ $agencyName }}</div>
    <div class="body">
        <p>Dear {{ $landlordName }},</p>
        <p>Your tenant has told us the following repair is not complete:</p>
        <div class="job">
            <strong>{{ $title }}</strong>
            @if($address)<br>{{ $address }}@endif
        </div>
        <p>In the tenant's words:</p>
        <div class="quote">{{ $tenantNote }}</div>
        @if(count($photoUrls))
            <p>Photos from the tenant:</p>
            <ul>@foreach($photoUrls as $u)<li><a href="{{ $u }}">{{ $u }}</a></li>@endforeach</ul>
        @endif
        <p class="muted">We are arranging for the work to be put right. You do not need to do anything; we will tell you when it is done.</p>
    </div>
    <div class="footer">
        {{ $footer['name'] ?? $agencyName }}@if(!empty($footer['phone'])) · {{ $footer['phone'] }}@endif @if(!empty($footer['email']))· {{ $footer['email'] }}@endif
    </div>
</div>
</body>
</html>
