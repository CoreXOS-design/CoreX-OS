<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>{{ $title }}</title>
<style>
    @page { margin: 96pt 46pt 70pt 46pt; }
    body { margin:0; font-family: 'DejaVu Sans', sans-serif; }
    #hdr { position: fixed; top: -80pt; left: 0; right: 0; height: 62pt; border-bottom: 1.2pt solid #0b2a4a; }
    #hdr table { width:100%; border-collapse: collapse; }
    #hdr td { vertical-align: middle; padding:0; }
    #hdr .logo img { display:block; } /* size: PlatformCompany::logoBoxPt (45pt high, ≤45% of the header) */
    #hdr .brand { font-size: 17pt; font-weight: bold; color:#0b2a4a; padding-left: 7pt; }
    #hdr .brand span { color:#00b4d8; }
    #hdr .co { text-align:right; font-size: 7.3pt; line-height: 1.35; color:#334155; }
    #hdr .co b { font-size: 8.6pt; color:#0b2a4a; }
    #ftr { position: fixed; bottom: -52pt; left: 0; right: 0; height: 40pt; border-top: 0.6pt solid #94a3b8; font-size: 7.3pt; color:#334155; padding-top: 4pt; }
    #ftr table { width:100%; border-collapse: collapse; }
    #ftr td { padding:0; vertical-align: top; }
    #ftr .r { text-align:right; }
    #ftr .ini { display:inline-block; width: 28pt; height: 12pt; line-height: 12pt; border: 0.6pt solid #64748b; padding: 0 2pt; text-align:center; vertical-align: middle; font-weight: bold; color:#0b2a4a; }
    .pn:before { content: counter(page); }
    .pg { page-break-after: always; }
    .pg.last { page-break-after: auto; }
    .cert { font-size: 8.6pt; }
    .cert h2 { font-size: 13pt; color:#0b2a4a; margin: 0 0 6pt; }
    .cert table { width:100%; border-collapse: collapse; margin-bottom: 10pt; }
    .cert th, .cert td { text-align:left; border-bottom:1px solid #e5e7eb; padding:3pt 4pt; vertical-align: top; }
    .cert th { color:#6b7280; font-weight:600; font-size:7.5pt; text-transform: uppercase; }
</style>
@include('platform-esign.agreement._css', ['pdf' => true])
</head><body>
<div id="hdr"><table><tr>
    <td class="logo" style="width:{{ $logoBox['w'] + 8 }}pt;"><img src="{{ $logo }}" alt="{{ $brand }}" width="{{ $logoBox['w'] }}" height="{{ $logoBox['h'] }}" style="width:{{ $logoBox['w'] }}pt; height:{{ $logoBox['h'] }}pt;"></td>
    <td class="co"><b>{{ $letterhead['name'] }}</b><br>{{ $letterhead['address'] }}<br>{{ $letterhead['contact'] }}</td>
</tr></table></div>
<div id="ftr"><table><tr>
    <td>CoreX OS Subscription Agreement &middot; {{ $versionLabel }} &middot; Page <span class="pn"></span> of {{ $total }}</td>
    <td class="r">@if($mode !== 'wet')Initials: Agency <span class="ini">{{ $initials['agency'] ?? '' }}</span> &nbsp;RR <span class="ini">{{ $initials['rr'] ?? '' }}</span>@else Initials: Agency <span class="ini">&nbsp;&nbsp;</span> &nbsp;RR <span class="ini">&nbsp;&nbsp;</span>@endif</td>
</tr></table></div>

@foreach($pages as $i => $p)
    <div class="pg agr {{ ($i === count($pages) - 1 && empty($cert)) ? 'last' : '' }}">{!! implode("\n", $p['blocks']) !!}</div>
@endforeach

@if(!empty($cert))
    @php $doc = $cert['doc']; @endphp
    <div class="cert last">
        <h2>Electronic signature record</h2>
        <p>{{ $doc->title }} &middot; contract {{ $doc->contract_ref }} &middot; {{ $versionLabel }}@if($doc->agency) &middot; {{ $doc->agency->name }}@endif<br>
        Sent {{ $doc->sent_at?->format('j F Y H:i') }} &middot; completed {{ $doc->completed_at?->format('j F Y H:i') }} &middot; {{ $cert['pages_initialled'] }} pages initialled by each party</p>
        <table>
            <tr><th>Signer</th><th>Role</th><th>ID / passport</th><th>Signed</th><th>IP</th></tr>
            @foreach($doc->signers as $s)
                <tr><td>{{ $s->typed_name }}<br><span style="color:#6b7280;">{{ $s->email }}</span></td><td>{{ $s->role_label }}</td><td>{{ $s->id_number }}</td><td>{{ $s->signed_at?->format('j M Y H:i') }}</td><td>{{ $s->signed_ip }}</td></tr>
            @endforeach
        </table>
        <p>Consent given by each signer: &ldquo;{{ $cert['consent'] }}&rdquo;</p>
        <table>
            <tr><th>When</th><th>Event</th><th>Detail</th></tr>
            @foreach($doc->events as $e)
                <tr><td style="white-space:nowrap;">{{ $e->created_at?->format('j M Y H:i:s') }}</td><td>{{ str_replace('_', ' ', $e->event) }}</td><td>{{ $e->detail }}</td></tr>
            @endforeach
        </table>
    </div>
@endif
</body></html>
