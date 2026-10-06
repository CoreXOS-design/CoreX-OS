<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>{{ $doc->title }}</title>
<style>
    @page { margin: {{ $doc->isPdf() ? '0' : '45pt 50pt' }}; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color:#111827; line-height:1.5; margin:0; }
    h1 { font-size: 16pt; margin: 0 0 4pt; } h3 { font-size: 12pt; margin: 14pt 0 4pt; } h4 { font-size: 11pt; margin: 10pt 0 3pt; }
    p { margin: 0 0 8pt; } ul { margin: 0 0 8pt 16pt; padding:0; }
    .meta { color:#6b7280; font-size:9pt; margin-bottom:14pt; }
    .sig { margin-top:22pt; border-top:1px solid #d1d5db; padding-top:8pt; page-break-inside: avoid; }
    .sig img { height: 56pt; }
    .typed { font-family: DejaVu Serif, serif; font-style: italic; font-size: 17pt; }
    .pg { position: relative; width: {{ $w }}pt; height: {{ $h }}pt; overflow: hidden; page-break-after: always; }
    .pg img.bg { position:absolute; left:0; top:0; width:{{ $w }}pt; height:{{ $h }}pt; }
    .ov { position:absolute; overflow:hidden; font-size: 9pt; }
    .ov img { height: 100%; }
    .cert { {{ $doc->isPdf() ? 'margin: 45pt 50pt;' : 'page-break-before: always;' }} font-size: 9pt; }
    .cert h2 { font-size: 13pt; margin: 0 0 6pt; }
    .cert table { width:100%; border-collapse: collapse; margin-bottom: 10pt; }
    .cert th, .cert td { text-align:left; border-bottom:1px solid #e5e7eb; padding:3pt 4pt; vertical-align: top; }
    .cert th { color:#6b7280; font-weight:600; font-size:8pt; text-transform: uppercase; }
</style></head><body>

@if($doc->isPdf())
    @foreach($pages as $p)
        <div class="pg">
            @if($p['image'])<img class="bg" src="{{ $p['image'] }}">@endif
            @foreach($p['overlays'] as $o)
                <div class="ov" style="left:{{ $o['left'] }}pt; top:{{ $o['top'] }}pt; width:{{ $o['width'] }}pt; height:{{ $o['height'] }}pt;">
                    @if($o['type'] === 'signature')
                        @if($o['signer']->signature_image)<img src="{{ $o['signer']->signature_image }}">@else<span class="typed">{{ $o['signer']->typed_name }}</span>@endif
                    @elseif($o['type'] === 'initial')
                        <strong>{{ \App\Services\PlatformEsign\SealService::initials($o['signer']->typed_name ?? '') }}</strong>
                    @else
                        {{ $o['value'] }}
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
@else
    <h1>{{ $doc->title }}</h1>
    <div class="meta">{{ $doc->agency?->name ? $doc->agency->name . ' · ' : '' }}CoreX OS</div>
    {!! $doc->body_html_snapshot !!}
    @foreach($doc->signers as $s)
        <div class="sig">
            <strong>{{ $s->role_label }}</strong><br>
            @if($s->signature_image)<img src="{{ $s->signature_image }}" alt="Signature"><br>@else<span class="typed">{{ $s->typed_name }}</span><br>@endif
            <strong>{{ $s->typed_name }}</strong><br>
            {{ $s->signed_at?->format('j F Y \a\t H:i') }}
        </div>
    @endforeach
@endif

<div class="cert">
    <h2>Electronic signature record</h2>
    <p>{{ $doc->title }} &middot; contract #{{ $doc->id }} &middot; template version {{ $doc->template_version }}@if($doc->agency) &middot; {{ $doc->agency->name }}@endif<br>
    Sent {{ $doc->sent_at?->format('j F Y H:i') }} &middot; completed {{ $doc->completed_at?->format('j F Y H:i') }}</p>
    <table>
        <tr><th>Signer</th><th>Role</th><th>ID / passport</th><th>Signed</th><th>IP</th></tr>
        @foreach($doc->signers as $s)
            <tr><td>{{ $s->typed_name }}<br><span style="color:#6b7280;">{{ $s->email }}</span></td><td>{{ $s->role_label }}</td><td>{{ $s->id_number }}</td><td>{{ $s->signed_at?->format('j M Y H:i') }}</td><td>{{ $s->signed_ip }}</td></tr>
        @endforeach
    </table>
    <p>Consent given by each signer: &ldquo;{{ $consent }}&rdquo;</p>
    <table>
        <tr><th>When</th><th>Event</th><th>Detail</th></tr>
        @foreach($doc->events as $e)
            <tr><td style="white-space:nowrap;">{{ $e->created_at?->format('j M Y H:i:s') }}</td><td>{{ str_replace('_', ' ', $e->event) }}</td><td>{{ $e->detail }}</td></tr>
        @endforeach
    </table>
</div>
</body></html>
