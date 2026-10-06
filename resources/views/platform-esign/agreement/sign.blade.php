<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex, nofollow">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>CoreX OS Subscription Agreement — {{ $doc->agency?->name ?? $signer->name }}</title>
<style>body { margin:0; background:#e8ecf2; font-family:'Figtree',-apple-system,'Segoe UI',Roboto,Arial,sans-serif; color:#0f172a; }</style>
@include('platform-esign.agreement._css')
@include('platform-esign.agreement._sheets-css')
</head>
<body>
<div class="topbar">
    <div class="brand">CoreX <span>OS</span></div>
    <div class="stat">Subscription Agreement</div>
    <div class="sp">
        <span class="stat" id="pages-stat"></span>
        <span class="stat" id="savestate">All changes saved</span>
        <button type="button" id="todo-btn" class="btn ghost sm">Outstanding (<span id="todo-count">0</span>)</button>
    </div>
</div>
<div id="todo" class="todo" hidden role="dialog" aria-label="What is still outstanding">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.4rem;"><strong>What is still outstanding</strong><button type="button" id="todo-close" class="mini" aria-label="Close">×</button></div>
    <div id="todo-list"></div>
</div>

<main class="agr-wrap">
    <div class="panel">
        <p style="margin:0 0 .4rem;font-size:1rem;"><strong>Hi {{ $signer->name }},</strong> this is your CoreX OS Subscription Agreement{{ $doc->agency ? ' for ' . $doc->agency->name : '' }}.</p>
        <p style="margin:0 0 .75rem;font-size:.9rem;color:#475569;">Read it, fill in the boxes, <strong>initial every page</strong> (the button at the foot of each page), then sign at the end. Everything you type saves automatically — you can close this page and come back to the same link until {{ $doc->expires_at?->format('j F Y') }}.</p>
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;">
            <label for="ini-input" style="font-weight:600;font-size:.88rem;">Your initials</label>
            <input id="ini-input" class="fld" style="font:inherit;border:1px solid #94a3b8;border-radius:4px;padding:.35rem .5rem;width:6.5em;text-transform:uppercase;" maxlength="5" value="{{ $initialsSuggestion }}" autocomplete="off">
            <span style="font-size:.8rem;color:#64748b;">Used on every page. Fixed once you have initialled a page.</span>
        </div>
    </div>

    @include('platform-esign.agreement._sheets', ['pages' => $pages, 'total' => $total, 'versionLabel' => $versionLabel, 'interactive' => true])

    <div class="panel" id="final">
        <h2 style="margin:0 0 .5rem;font-size:1.05rem;color:#0b2a4a;">Submit your signed agreement</h2>
        <div style="display:flex;flex-wrap:wrap;gap:.6rem;align-items:center;margin-bottom:.7rem;">
            <label for="id_number" style="font-weight:600;font-size:.88rem;">ID or passport number of the person signing</label>
            <input id="id_number" class="fld" style="font:inherit;border:1px solid #94a3b8;border-radius:4px;padding:.35rem .5rem;width:14em;" maxlength="40" value="{{ $signer->id_number }}" autocomplete="off">
        </div>
        <label style="display:flex;gap:.55rem;align-items:flex-start;font-size:.88rem;margin-bottom:.8rem;"><input type="checkbox" id="consent" style="margin-top:.2rem;"> <span>{{ $consent }}</span></label>
        <div id="submit-errors" style="color:#b91c1c;font-size:.85rem;margin-bottom:.6rem;"></div>
        <button type="button" id="submit-btn" class="btn" disabled>Submit signed agreement</button>
        <span style="font-size:.8rem;color:#64748b;margin-left:.5rem;">Open “Outstanding” at the top to see what is left.</span>
    </div>
</main>
<div id="toast" class="toast" hidden role="status"></div>

@php
    $rates = array_merge(\App\Services\PlatformEsign\Agreement\AgreementPricing::DEFAULT_RATES, (array) $ctx['rates']);
    $labels = collect(\App\Services\PlatformEsign\Agreement\AgreementFields::schema())->map(fn ($f) => $f['label'])->all();
    $cfg = [
        'mode' => 'form', 'rev' => $rev, 'total' => $total, 'done' => $done, 'initials' => $signer->initials, 'signerName' => $signer->name,
        'rates' => $rates, 'variation' => (string) ($ctx['rr']['variation_amount'] ?? '0'), 'labels' => $labels,
        'recipientKeys' => \App\Services\PlatformEsign\Agreement\AgreementFields::recipientKeys(),
        'urls' => [
            'save' => route('platform-esign.agreement.save', $token), 'initials' => route('platform-esign.agreement.initials', $token),
            'page' => str_replace('/0', '/__P__', route('platform-esign.agreement.initial-page', [$token, 0])), 'submit' => route('platform-esign.agreement.submit', $token),
        ],
    ];
@endphp
<script>window.AGR = {!! json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) !!};</script>
@include('platform-esign.agreement._js')
</body>
</html>
