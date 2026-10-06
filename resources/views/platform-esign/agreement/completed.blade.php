<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<title>CoreX OS Subscription Agreement — {{ $doc->agency?->name ?? $signer->name }}</title>
<style>body { margin:0; background:#e8ecf2; font-family:'Figtree',-apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#0f172a; }
    .done-badge { display:inline-block; background:#dcfce7; color:#166534; border-radius:999px; padding:.15rem .7rem; font-weight:700; font-size:.85rem; }
    .done-actions { display:flex; flex-wrap:wrap; gap:.6rem; margin-top:.9rem; }
    .done-actions a.btn { text-decoration:none; }
    .done-files { margin:.6rem 0 0 1.1rem; padding:0; font-size:.9rem; color:#334155; }</style>
@include('platform-esign.agreement._css')
@include('platform-esign.agreement._sheets-css')
</head>
<body>
<div class="topbar">
    <div class="brand">CoreX <span>OS</span></div>
    <div class="stat">Subscription Agreement</div>
</div>
<main class="agr-wrap">
    <div class="panel">
        <p style="margin:0 0 .4rem;font-size:1rem;"><strong>{{ $doc->title }}</strong> · {{ $doc->contract_ref }}</p>
        <p style="margin:0 0 .3rem;">Status: <span class="done-badge">Completed</span></p>
        <p style="margin:0;color:#475569;font-size:.9rem;line-height:1.5;">Signed by both parties on {{ $doc->completed_at?->format('j F Y \a\t H:i') }}. This page is read-only. For your security your bank account numbers are masked here — the signed PDF carries the full agreement.@if($doc->expires_at) This link stays valid until {{ $doc->expires_at->format('j F Y') }}.@endif</p>
        <div class="done-actions">
            @if($hasPdf)<a class="btn" href="{{ route('platform-esign.agreement.download', $token) }}">Download the signed PDF{{ $handSigned ? ' (countersignature and attestation)' : '' }}</a>@endif
        </div>
        @if($files->isNotEmpty())
            <div style="margin-top:.9rem;font-weight:600;font-size:.9rem;">Your uploaded hand-signed copy</div>
            <ul class="done-files">@foreach($files as $f)<li><a href="{{ route('platform-esign.agreement.wet-file', [$token, $f->id]) }}">{{ $f->original_name }}</a> · {{ number_format(max(1, $f->size / 1024), 0) }} KB · {{ $f->created_at?->format('j M Y H:i') }}</li>@endforeach</ul>
        @endif
    </div>
    <div class="agr-wrap" style="padding:0;">
        @include('platform-esign.agreement._sheets', ['pages' => $pages, 'total' => $total, 'versionLabel' => $versionLabel, 'interactive' => false, 'staticIni' => $staticIni])
    </div>
</main>
</body>
</html>
