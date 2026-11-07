<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>New bidder registration</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 8px;font-size:1.25rem;color:#111827;">New bidder registration</h1>
<p style="margin:0 0 20px;font-size:.9375rem;color:#6b7280;">{{ $who }} registered to bid through the {{ $agencyName }} auction page and is waiting for your approval.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:.9375rem;color:#111827;width:100%;">
@if($auction)<tr><td style="padding:4px 0;color:#6b7280;width:110px;">Auction</td><td>{{ $auction->reference }} — {{ $auction->title }}</td></tr>@endif
<tr><td style="padding:4px 0;color:#6b7280;">Name</td><td>{{ $who }}</td></tr>
<tr><td style="padding:4px 0;color:#6b7280;">Type</td><td>{{ \App\Models\AuctionRegistration::TYPES[$registration->bidder_type] ?? $registration->bidder_type }}</td></tr>
@if($email)<tr><td style="padding:4px 0;color:#6b7280;">Email</td><td>{{ $email }}</td></tr>@endif
@if($phone)<tr><td style="padding:4px 0;color:#6b7280;">Phone</td><td>{{ $phone }}</td></tr>@endif
</table>
<p style="margin:24px 0 0;"><a href="{{ $biddersUrl }}" style="display:inline-block;background:#0b2a4a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:.9375rem;">Review the registration</a></p>
</td></tr></table>
</td></tr></table>
</body>
</html>
