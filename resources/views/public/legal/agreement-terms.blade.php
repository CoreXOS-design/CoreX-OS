{{-- Public, logged-out: CoreX OS Subscription Agreement Parts B, C, D (spec §11.14). $v, $isCurrent, $current, $published, $parts, $letterhead, $logoUrl, $brand, $printJs (the one inline script; the CSP allows exactly its hash). --}}
<!DOCTYPE html>
<html lang="en-ZA">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>CoreX OS Subscription Agreement — Terms of Service, POPIA Operator Terms, Support and Acceptable Use ({{ $v->label() }})</title>
<meta name="description" content="Parts B, C and D of the CoreX OS Subscription Agreement published by {{ $letterhead['name'] }}: Terms of Service, POPIA Operator Terms, and Support and Acceptable Use. {{ $v->label() }}.">
<link rel="canonical" href="{{ $isCurrent ? route('public.agreement-terms') : route('public.agreement-terms.version', $v->version) }}">
<meta name="robots" content="{{ $isCurrent ? 'index, follow' : 'noindex, follow' }}">
<style>
    :root { --brand:#0b2a4a; --accent:#00b4d8; --text:#1f2937; --muted:#64748b; --border:#d9dee8; --bg:#eef1f6; }
    * { box-sizing: border-box; }
    body { margin:0; background:var(--bg); color:var(--text); font-family:'Figtree',-apple-system,'Segoe UI',Roboto,Arial,sans-serif; line-height:1.55; }
    .wrap { max-width: 860px; margin: 0 auto; padding: 0 16px 64px; }
    .letterhead { display:flex; align-items:center; gap:14px; padding:18px 0 12px; border-bottom:2px solid var(--brand); margin-bottom:20px; }
    .letterhead img { max-width:45%; object-fit:contain; object-position:left center; flex:none; } /* size from PlatformCompany::logoSizePx */
    .letterhead .co { margin-left:auto; text-align:right; font-size:.72rem; line-height:1.45; color:#334155; }
    .letterhead .co b { font-size:.82rem; color:var(--brand); }
    .doc { background:#fff; border:1px solid var(--border); border-radius:6px; padding: 28px 44px 36px; box-shadow:0 1px 3px rgba(15,23,42,.06); }
    .page-title { font-size:1.55rem; line-height:1.25; color:var(--brand); margin:0 0 .35rem; }
    .vline { display:flex; flex-wrap:wrap; gap:.4rem .8rem; align-items:center; color:var(--muted); font-size:.92rem; margin-bottom:1rem; }
    .badge { display:inline-block; font-size:.72rem; font-weight:700; letter-spacing:.02em; text-transform:uppercase; padding:.1rem .5rem; border-radius:999px; background:#e0f7fb; color:#0e6b7e; }
    .badge.old { background:#fef3c7; color:#92400e; }
    .notice { background:#fffbeb; border:1px solid #fde68a; border-radius:6px; padding:.65rem .9rem; margin:0 0 1rem; font-size:.92rem; }
    nav.toc, nav.versions { font-size:.92rem; margin-bottom:1.2rem; padding:.7rem .9rem; background:#f8fafc; border:1px solid var(--border); border-radius:6px; }
    nav.toc a, nav.versions a { color:var(--brand); }
    nav.versions ul { list-style:none; margin:.3rem 0 0; padding:0; }
    nav.versions li { padding:.1rem 0; }
    nav h2 { font-size:.8rem; text-transform:uppercase; letter-spacing:.05em; color:var(--muted); margin:0; }
    section.part { margin-top: 1.6rem; padding-top:.2rem; }
    section.part:first-of-type { margin-top: .4rem; }
    .foot { margin-top:2rem; padding-top:.8rem; border-top:1px solid var(--border); font-size:.78rem; color:var(--muted); display:flex; flex-wrap:wrap; justify-content:space-between; gap:.3rem 1rem; }
    .printbtn { margin-left:auto; font:inherit; font-size:.82rem; background:#fff; border:1px solid #94a3b8; border-radius:6px; padding:.3rem .8rem; cursor:pointer; color:var(--brand); }
    @media (max-width: 640px) {
        .doc { padding: 18px 16px 24px; border-left:0; border-right:0; border-radius:0; }
        .wrap { padding: 0 0 48px; }
        .letterhead { padding:14px 16px 10px; flex-wrap:wrap; }
        .letterhead .co { margin-left:0; text-align:left; }
        .page-title { font-size:1.3rem; }
        .agr table { display:block; overflow-x:auto; }
    }
    @media print {
        body { background:#fff; }
        .wrap { max-width:none; padding:0; }
        .doc { border:0; box-shadow:none; padding:0; }
        nav.toc, nav.versions, .printbtn, .notice { display:none !important; }
        section.part { page-break-before: always; margin-top:0; }
        section.part:first-of-type { page-break-before: avoid; }
        .agr h1, .agr h2, .agr h3 { page-break-after: avoid; }
        .agr p, .agr li, .agr tr, .agr blockquote { page-break-inside: avoid; }
        a { color: inherit; text-decoration: none; }
    }
</style>
@include('platform-esign.agreement._css')
</head>
<body>
<div class="wrap">
    <header class="letterhead">
        @php($lg = \App\Services\PlatformEsign\Agreement\AgreementCompany::for(null)->logoSizePx(860))
        <img src="{{ $logoUrl }}" alt="{{ $brand }}" width="{{ $lg['w'] }}" height="{{ $lg['h'] }}" style="width:{{ $lg['w'] }}px; height:{{ $lg['h'] }}px;">
        <div class="co"><b>{{ $letterhead['name'] }}</b><br>{{ $letterhead['address'] }}<br>{{ $letterhead['contact'] }}</div>
    </header>

    <main class="doc">
        <h1 class="page-title">CoreX OS Subscription Agreement — Parts B, C and D</h1>
        <div class="vline">
            <span>{{ $v->label() }}</span>
            <span class="badge {{ $isCurrent ? '' : 'old' }}">{{ $isCurrent ? 'Current version' : 'Earlier version' }}</span>
            <button type="button" class="printbtn">Print or save as PDF</button>
        </div>

        @unless($isCurrent)
            <p class="notice">This is an earlier version of these terms, published as {{ $v->label() }}. The current version is <a href="{{ route('public.agreement-terms') }}">{{ $current->label() }}</a>.</p>
        @endunless

        <nav class="toc" aria-label="Parts">
            <h2>On this page</h2>
            @foreach($parts as $key => $p)<a href="#{{ str_replace('_', '-', $key) }}">{{ $p['label'] }}</a>@unless($loop->last) &nbsp;·&nbsp; @endunless @endforeach
        </nav>

        @foreach($parts as $key => $p)
            <section class="part agr" id="{{ str_replace('_', '-', $key) }}">{!! $p['html'] !!}</section>
        @endforeach

        <nav class="versions" aria-label="Versions">
            <h2>Versions of these terms</h2>
            <ul>
                @foreach($published as $row)
                    <li>
                        @if($row->id === $v->id)<strong>Version {{ $row->version }} — {{ $row->version_date->format('j F Y') }}</strong>@else<a href="{{ $row->id === $current->id ? route('public.agreement-terms') : route('public.agreement-terms.version', $row->version) }}">Version {{ $row->version }} — {{ $row->version_date->format('j F Y') }}</a>@endif
                        @if($row->id === $current->id) <span class="badge">Current</span>@endif
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="foot"><span>{{ $letterhead['name'] }} · {{ $letterhead['contact'] }}</span><span>{{ $v->label() }}</span></div>
    </main>
</div>
<script>{!! $printJs !!}</script>
</body>
</html>
