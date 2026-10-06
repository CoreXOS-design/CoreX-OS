<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Countersign</title></head>
@php($co = \App\Services\PlatformEsign\Agreement\AgreementCompany::for($doc))
<body style="margin:0; padding:0; background:#f4f6fb; font-family:-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6fb; padding:40px 16px;"><tr><td align="center">
<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px; width:100%;">
<tr><td align="center" style="padding-bottom:32px;"><div style="font-size:1.75rem; font-weight:800; letter-spacing:-0.04em; color:#0b2a4a; line-height:1;">CoreX <span style="color:#00b4d8;">OS</span></div></td></tr>
<tr><td style="background:#ffffff; border-radius:16px; border:1px solid #e5e7eb; padding:40px 36px;">
<h1 style="margin:0 0 8px; font-size:1.375rem; font-weight:700; color:#111827;">{{ $wetInk ? 'Signed copy received' : 'The agency has signed' }}</h1>
<p style="margin:0 0 24px; font-size:0.9375rem; line-height:1.6; color:#6b7280;">
    {{ $doc->signers->first()?->name }} {{ $wetInk ? 'uploaded a hand-signed copy of' : 'completed and signed' }} <strong style="color:#111827;">{{ $doc->title }}</strong>. It now needs {{ $co->legalName() }}’s countersignature. Open it to review and countersign.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto 8px;"><tr>
    <td align="center" style="background:#00b4d8; border-radius:8px;">
        <a href="{{ $url }}" target="_blank" style="display:inline-block; padding:14px 36px; color:#ffffff; font-size:0.9375rem; font-weight:600; text-decoration:none;">Review and countersign</a>
    </td></tr></table>
</td></tr>
<tr><td align="center" style="padding-top:24px; font-size:0.75rem; color:#9ca3af;">{{ $co->brand() }}@if($co->website()) &middot; {{ $co->website() }}@endif</td></tr>
</table></td></tr></table></body></html>
