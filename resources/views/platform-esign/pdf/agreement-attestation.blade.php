<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>Countersignature and attestation</title>
<style>
    @page { margin: 96pt 46pt 70pt 46pt; }
    body { margin:0; font-family: 'DejaVu Sans', sans-serif; font-size: 9pt; color:#111827; line-height:1.45; }
    #hdr { position: fixed; top: -80pt; left: 0; right: 0; height: 62pt; border-bottom: 1.2pt solid #0b2a4a; }
    #hdr table { width:100%; border-collapse: collapse; } #hdr td { vertical-align: middle; padding:0; }
    #hdr .logo img { height: 34pt; }
    #hdr .co { text-align:right; font-size: 7.3pt; line-height: 1.35; color:#334155; } #hdr .co b { font-size: 8.6pt; color:#0b2a4a; }
    #ftr { position: fixed; bottom: -52pt; left: 0; right: 0; height: 40pt; border-top: 0.6pt solid #94a3b8; font-size: 7.3pt; color:#334155; padding-top: 4pt; }
    .pn:before { content: counter(page); }
    h1 { font-size: 14pt; color:#0b2a4a; margin: 0 0 8pt; } h2 { font-size: 11pt; color:#0b2a4a; margin: 12pt 0 4pt; }
    table.t { width:100%; border-collapse: collapse; margin-bottom: 8pt; } table.t th, table.t td { text-align:left; border:1px solid #cbd5e1; padding:3pt 4pt; vertical-align: top; } table.t th { background:#f1f5f9; font-size: 8pt; }
    .sig img { height: 40pt; } .mono { font-family: 'DejaVu Sans Mono', monospace; font-size: 7pt; word-break: break-all; }
    .cert { page-break-before: always; font-size: 8.6pt; } .cert th, .cert td { border:0; border-bottom:1px solid #e5e7eb; }
</style></head><body>
<div id="hdr"><table><tr>
    <td class="logo"><img src="{{ $logo }}" alt="{{ $brand }}"></td>
    <td class="co"><b>{{ $letterhead['name'] }}</b><br>{{ $letterhead['address'] }}<br>{{ $letterhead['contact'] }}</td>
</tr></table></div>
<div id="ftr">CoreX OS Subscription Agreement &middot; {{ $versionLabel }} &middot; Countersignature and attestation &middot; Page <span class="pn"></span> of {{ $total }}</div>

<h1>Countersignature and attestation</h1>
<p>Subscription Agreement <strong>{{ $doc->contract_ref }}</strong> &middot; {{ $versionLabel }}@if($doc->agency) &middot; {{ $doc->agency->name }}@endif</p>
<p>The agency signed this agreement by hand and returned the signed copy listed below. {{ $letterhead['name'] }} has reviewed that copy and countersigns it by electronic signature on this page. The signed agreement is the agency’s hand-signed copy together with this attestation; the SHA-256 fingerprints below identify exactly which files were received.</p>

<h2>Signed copy received from the agency</h2>
<table class="t">
    <tr><th>File</th><th>Received</th><th>Size</th><th>SHA-256</th></tr>
    @foreach($files as $f)
        <tr><td>{{ $f->original_name }}</td><td>{{ $f->created_at?->format('j M Y H:i') }}</td><td>{{ number_format(max(1, $f->size / 1024), 0) }} KB</td><td class="mono">{{ $f->sha256 }}</td></tr>
    @endforeach
</table>

<h2>Countersigned for {{ $letterhead['name'] }}</h2>
<table class="t">
    <tr><th width="25%">Name</th><td>{{ $rr['rr_name'] ?? '' }}</td></tr>
    <tr><th>Capacity</th><td>{{ $rr['rr_capacity'] ?? '' }}</td></tr>
    <tr><th>Place</th><td>{{ $rr['rr_place'] ?? '' }}</td></tr>
    <tr><th>Date</th><td>{{ !empty($rr['rr_date']) ? \Carbon\Carbon::parse($rr['rr_date'])->format('j F Y') : '' }}</td></tr>
    <tr><th>Signature</th><td class="sig">@if(!empty($rr['sigR']))<img src="{{ $rr['sigR'] }}" alt="Signature">@endif</td></tr>
</table>

<div class="cert">
    <h1>Electronic signature record</h1>
    <p>{{ $doc->title }} &middot; contract {{ $doc->contract_ref }} &middot; {{ $versionLabel }}<br>Sent {{ $doc->sent_at?->format('j F Y H:i') }} &middot; completed {{ $doc->completed_at?->format('j F Y H:i') }}</p>
    <table class="t">
        <tr><th>Signer</th><th>Role</th><th>Signed</th><th>IP</th></tr>
        @foreach($doc->signers as $s)<tr><td>{{ $s->typed_name ?: $s->name }}<br><span style="color:#6b7280;">{{ $s->email }}</span></td><td>{{ $s->role_label }}</td><td>{{ $s->signed_at?->format('j M Y H:i') }}</td><td>{{ $s->signed_ip }}</td></tr>@endforeach
    </table>
    <p>Consent given: &ldquo;{{ $consent }}&rdquo;</p>
    <table class="t">
        <tr><th>When</th><th>Event</th><th>Detail</th></tr>
        @foreach($doc->events as $e)<tr><td style="white-space:nowrap;">{{ $e->created_at?->format('j M Y H:i:s') }}</td><td>{{ str_replace('_', ' ', $e->event) }}</td><td>{{ $e->detail }}</td></tr>@endforeach
    </table>
</div>
</body></html>
