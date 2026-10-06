{{-- Standard platform email signature built from the company record. Table + inline styles for email clients. --}}
@php
    $trading = trim((string) $c->trading_name) ?: trim((string) $c->legal_name);
    $legal = trim((string) $c->legal_name);
    $line = array_filter([
        trim((string) $c->email_general) !== '' ? trim($c->email_general) : null,
        ...array_map(fn ($p) => ($p['label'] !== '' ? $p['label'] . ' ' : '') . $p['number'], $c->phoneList()),
    ]);
    $support = trim((string) $c->email_support);
    $lg = $c->logoSizePx(600); // the same letterhead rule: 60px high, never upscaled, at most 45% of the width
@endphp
<table role="presentation" cellpadding="0" cellspacing="0" style="font-family:Arial,Helvetica,sans-serif; font-size:13px; line-height:1.5; color:#374151;">
<tr>
<td style="padding:0 14px 0 0; border-right:3px solid #00b4d8; vertical-align:middle;"><img src="{{ $c->logoUrl() }}" alt="{{ $trading }}" width="{{ $lg['w'] }}" height="{{ $lg['h'] }}" style="display:block; width:{{ $lg['w'] }}px; height:{{ $lg['h'] }}px;"></td>
<td style="padding:0 0 0 14px; vertical-align:middle;">
<div style="font-size:14px; font-weight:700; color:#0b2a4a;">{{ $trading }}</div>
@if($legal !== '' && strcasecmp($legal, $trading) !== 0)<div>{{ $legal }}</div>@endif
@foreach($line as $l)<div>{{ $l }}</div>@endforeach
@if($support !== '')<div>Support: {{ $support }}</div>@endif
@if($c->websiteList())<div>@foreach($c->websiteList() as $i => $w)@if($i > 0) &middot; @endif<a href="{{ \App\Models\Platform\PlatformCompany::websiteHref($w) }}" style="color:#0b2a4a; text-decoration:none;">{{ $w }}</a>@endforeach</div>@endif
</td>
</tr>
</table>
