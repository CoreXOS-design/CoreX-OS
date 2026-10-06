<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Signed</title></head>
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">
<tr><td align="center" style="padding-bottom:32px;"><div style="font-size:1.75rem; font-weight:800; letter-spacing:-0.04em; color:#0b2a4a; line-height:1;">CoreX <span style="color:#00b4d8;">Os</span></div></td></tr>
<tr><td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">
<h1 style="margin:0 0 8px; font-size:1.375rem; font-weight:700; color:#111827;">Signed — {{ $doc->title }}</h1>
<p style="margin:0 0 16px; font-size:0.9375rem; line-height:1.6; color:#6b7280;">
    <strong style="color:#111827;">{{ $doc->title }}</strong> has been signed by everyone ({{ $doc->signers->pluck('name')->implode(', ') }}) and completed on {{ $doc->completed_at?->format('j F Y \a\t H:i') }}.
</p>
<p style="margin:0; font-size:0.9375rem; line-height:1.6; color:#6b7280;">@if($doc->sealed_pdf_path)The sealed PDF copy is attached. Keep it for your records.@else The sealed PDF is being prepared and will be sent separately.@endif</p>
</td></tr>
@include('platform-esign.email._signature')
</table></td></tr></table></body></html>
