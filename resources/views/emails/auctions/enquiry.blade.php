<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>New auction enquiry</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 8px;font-size:1.25rem;color:#111827;">New auction enquiry</h1>
<p style="margin:0 0 20px;font-size:.9375rem;color:#6b7280;">{{ $enquiry->name }} sent an enquiry through the {{ $agencyName }} auction page.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:.9375rem;color:#111827;width:100%;">
@if($auction)<tr><td style="padding:4px 0;color:#6b7280;width:110px;">Auction</td><td>{{ $auction->reference }} — {{ $auction->title }}</td></tr>@endif
@if($lot)<tr><td style="padding:4px 0;color:#6b7280;">Lot</td><td>Lot {{ $lot->lot_number }} — {{ $lot->displayTitle() }}</td></tr>@endif
<tr><td style="padding:4px 0;color:#6b7280;">Name</td><td>{{ $enquiry->name }}</td></tr>
@if($enquiry->email)<tr><td style="padding:4px 0;color:#6b7280;">Email</td><td>{{ $enquiry->email }}</td></tr>@endif
@if($enquiry->phone)<tr><td style="padding:4px 0;color:#6b7280;">Phone</td><td>{{ $enquiry->phone }}</td></tr>@endif
</table>
@if($enquiry->message)<p style="margin:20px 0 0;padding:14px;background:#f9fafb;border-radius:8px;font-size:.9375rem;color:#111827;white-space:pre-line;">{{ $enquiry->message }}</p>@endif
<p style="margin:24px 0 0;"><a href="{{ $enquiriesUrl }}" style="display:inline-block;background:#0b2a4a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:.9375rem;">Open the enquiry</a></p>
</td></tr></table>
</td></tr></table>
</body>
</html>
