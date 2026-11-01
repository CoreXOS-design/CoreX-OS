<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>New seller lead</title></head>
<body style="margin:0;padding:0;background:#f4f6fb;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:32px 16px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:32px;">
<tr><td>
<h1 style="margin:0 0 8px;font-size:1.25rem;color:#111827;">New seller lead</h1>
<p style="margin:0 0 20px;font-size:.9375rem;color:#6b7280;">{{ $s->name }} filled in "Sell at auction" on the {{ $agencyName }} auction page and would like a valuation and auction proposal.</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="font-size:.9375rem;color:#111827;width:100%;">
<tr><td style="padding:4px 0;color:#6b7280;width:130px;">Name</td><td>{{ $s->name }}</td></tr>
<tr><td style="padding:4px 0;color:#6b7280;">Email</td><td>{{ $s->email }}</td></tr>
<tr><td style="padding:4px 0;color:#6b7280;">Mobile</td><td>{{ $s->phone }}</td></tr>
<tr><td style="padding:4px 0;color:#6b7280;">Property</td><td>{{ $propertyType }}</td></tr>
<tr><td style="padding:4px 0;color:#6b7280;">Address</td><td>{{ $s->address }}, {{ $s->suburb }}</td></tr>
@if($price)<tr><td style="padding:4px 0;color:#6b7280;">Price hoped for</td><td>{{ $price }}</td></tr>@endif
@if($format)<tr><td style="padding:4px 0;color:#6b7280;">Auction format</td><td>{{ $format }}</td></tr>@endif
</table>
<p style="margin:24px 0 0;"><a href="{{ $leadUrl }}" style="display:inline-block;background:#0b2a4a;color:#fff;text-decoration:none;padding:10px 18px;border-radius:8px;font-size:.9375rem;">Open the lead</a></p>
</td></tr></table>
</td></tr></table>
</body>
</html>
