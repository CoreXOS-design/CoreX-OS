{{-- Platform company letterhead header. $c = PlatformCompany, $context = 'web' | 'pdf'. Self-contained inline styles only.
     Optional values are OMITTED, never printed as an empty label. Spec: .ai/specs/platform-company-profile.md §4. --}}
@php
    $pdf = $context === 'pdf';
    $logo = $pdf ? $c->logoDataUri() : $c->logoUrl();
    $lgPx = $c->logoSizePx(760);   // the one letterhead logo rule (PlatformCompany): fixed height, never upscaled, ≤45% of the header
    $lgPt = $c->logoBoxPt();
    $trading = trim((string) $c->trading_name);
    $legal = trim((string) $c->legal_name);
    $showLegal = $legal !== '' && strcasecmp($legal, $trading) !== 0;
    $name = $trading !== '' ? $trading : $legal;
    $addr = $c->addressLines();
    $facts = array_filter([
        trim((string) $c->registration_number) !== '' ? 'Reg. no ' . trim($c->registration_number) : null,
        ($c->vat_registered && trim((string) $c->vat_number) !== '') ? 'VAT no ' . trim($c->vat_number) : null,
    ]);
    $emails = array_filter([
        'Email' => trim((string) $c->email_general),
        'Support' => trim((string) $c->email_support),
        'Accounts' => trim((string) $c->email_accounts),
    ]);
    $fs = $pdf ? '9pt' : '12px';
    $fsName = $pdf ? '14pt' : '16px';
    $ink = $pdf ? '#111111' : '#374151';
    $accent = $pdf ? '#0b2a4a' : '#00b4d8';
@endphp
@if($pdf)
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="width:100%; border-collapse:collapse; border-bottom:2px solid {{ $accent }}; font-family:Arial,Helvetica,sans-serif; color:{{ $ink }};">
    <tr>
        <td style="vertical-align:middle; padding:0 0 10px 0; width:45%;">
            <img src="{{ $logo }}" alt="{{ $name }}" width="{{ $lgPt['w'] }}" height="{{ $lgPt['h'] }}" style="width:{{ $lgPt['w'] }}pt; height:{{ $lgPt['h'] }}pt; display:block;">
            @if(trim((string) $c->strap_line) !== '')<div style="margin-top:4px; font-size:8.5pt; font-style:italic; color:#4b5563;">{{ trim($c->strap_line) }}</div>@endif
        </td>
        <td style="vertical-align:middle; padding:0 0 10px 0; text-align:right; font-size:{{ $fs }}; line-height:1.5;">
@else
<div style="display:flex; flex-wrap:wrap; align-items:center; justify-content:space-between; gap:16px 28px; padding:0 0 14px 0; border-bottom:2px solid {{ $accent }}; font-family:Arial,Helvetica,sans-serif; color:{{ $ink }};">
    <div style="flex:0 1 auto; min-width:0;">
        <img src="{{ $logo }}" alt="{{ $name }}" width="{{ $lgPx['w'] }}" height="{{ $lgPx['h'] }}" style="width:{{ $lgPx['w'] }}px; height:{{ $lgPx['h'] }}px; max-width:100%; display:block;">
        @if(trim((string) $c->strap_line) !== '')<div style="margin-top:4px; font-size:12px; font-style:italic; color:#6b7280;">{{ trim($c->strap_line) }}</div>@endif
    </div>
    <div style="flex:1 1 260px; text-align:right; font-size:{{ $fs }}; line-height:1.55;">
@endif
            <div style="font-size:{{ $fsName }}; font-weight:700; color:#0b2a4a; line-height:1.25;">{{ $name }}</div>
            @if($showLegal)<div style="font-weight:600;">{{ $legal }}</div>@endif
            @if($facts)<div>{{ implode(' · ', $facts) }}</div>@endif
            @if($addr)<div>{{ implode(', ', $addr) }}</div>@endif
            @foreach($emails as $label => $addrEmail)<div>{{ $label }}: {{ $addrEmail }}</div>@endforeach
            @foreach($c->phoneList() as $p)<div>{{ $p['label'] !== '' ? $p['label'] . ': ' : '' }}{{ $p['number'] }}</div>@endforeach
            @if($c->websiteList())<div>{{ implode(' · ', $c->websiteList()) }}</div>@endif
@if($pdf)
        </td>
    </tr>
</table>
@else
    </div>
</div>
@endif
