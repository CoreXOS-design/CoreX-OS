<!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><title>{{ $env->title }}</title>
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 10.5pt; color:#111827; line-height:1.5; }
    h1 { font-size: 16pt; margin: 0 0 4pt; } h3 { font-size: 12pt; margin: 14pt 0 4pt; } h4 { font-size: 11pt; margin: 10pt 0 3pt; }
    p { margin: 0 0 8pt; } ul { margin: 0 0 8pt 16pt; padding:0; }
    .meta { color:#6b7280; font-size:9pt; margin-bottom:14pt; }
    .sig { margin-top:26pt; border-top:1px solid #d1d5db; padding-top:10pt; page-break-inside: avoid; }
    .sig img { height: 60pt; }
    .audit { margin-top:16pt; font-size:8.5pt; color:#4b5563; border:1px solid #e5e7eb; padding:8pt; page-break-inside: avoid; }
</style></head><body>
<h1>{{ $env->title }}</h1>
<div class="meta">{{ $env->agency?->name }} &middot; CoreX OS</div>

{!! $env->body_html_snapshot !!}

<div class="sig">
    <strong>Signed on behalf of {{ $env->agency?->name }}</strong><br>
    @if($env->signature_image)<img src="{{ $env->signature_image }}" alt="Signature"><br>@endif
    <strong>{{ $env->signed_typed_name }}</strong>, {{ $env->signatory_role }}<br>
    {{ $env->signed_at?->format('j F Y \a\t H:i') }}
</div>

<div class="audit">
    <strong>Electronic signature record</strong><br>
    Signatory: {{ $env->signatory_name }} &lt;{{ $env->signatory_email }}&gt;<br>
    Signed from IP: {{ $env->signed_ip }}<br>
    Consent given: &ldquo;{{ $consent }}&rdquo;<br>
    The text above is the exact wording that was sent and signed (contract #{{ $env->id }}, template version {{ $env->template_version }}).
</div>
</body></html>
