<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $siteTitle) — {{ $siteTitle }}</title>
    <meta name="robots" content="index,follow">
    <meta name="color-scheme" content="light">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { color-scheme: light; --brand: {{ $accent }}; --button: {{ $branding['colors']['button'] }}; --on-button: {{ \App\Support\ColorContrast::textOn($branding['colors']['button']) }}; --on-brand: {{ \App\Support\ColorContrast::textOn($accent) }}; --ink:#151b26; --muted:#5b6576; --line:#e1e6ee; --bg:#f4f6fa; --card:#fff; }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; flex-direction:column; background:var(--bg); color:var(--ink); font:16px/1.5 Manrope,-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        a { color: var(--button); }
        header.top { background:#fff; color:var(--ink); border-bottom:1px solid var(--line); }
        .bar { max-width:1080px; margin:0 auto; padding:14px 16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .bar .brand { display:flex; align-items:center; gap:12px; color:var(--brand); text-decoration:none; font-weight:800; font-size:1.2rem; letter-spacing:-.01em; }
        .bar img { height:40px; width:auto; border-radius:4px; }
        .bar nav { margin-left:auto; display:flex; gap:16px; align-items:center; flex-wrap:wrap; font-size:.92rem; font-weight:600; }
        .bar nav a, .bar nav button { color:var(--muted); text-decoration:none; background:none; border:0; cursor:pointer; font:inherit; padding:0; }
        .bar nav a.signup { color:var(--brand); border:1.5px solid var(--brand); border-radius:10px; padding:6px 14px; }
        main { flex:1 0 auto; width:100%; box-sizing:border-box; max-width:1080px; margin:0 auto; padding:20px 16px 48px; }
        h1 { font-size:1.6rem; margin:.2rem 0 .4rem; } h2 { font-size:1.15rem; margin:1.6rem 0 .6rem; }
        .muted { color:var(--muted); } .small { font-size:.85rem; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:16px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .card .pad { padding:14px 16px; }
        .card img.cover { width:100%; height:170px; object-fit:cover; display:block; background:var(--line); }
        .badge { display:inline-block; font-size:.75rem; font-weight:600; padding:2px 9px; border-radius:99px; background:var(--line); color:var(--ink); }
        .badge.ok { background:#dcfce7; color:#166534; } .badge.warn { background:#fef3c7; color:#92400e; } .badge.bad { background:#fee2e2; color:#991b1b; }
        .btn { display:inline-block; background:var(--button); color:var(--on-button) !important; border:0; border-radius:8px; padding:10px 18px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
        .btn.ghost { background:transparent; color:var(--button) !important; border:1px solid var(--button); }
        .btn[disabled] { opacity:.55; cursor:not-allowed; }
        form.stack label { display:block; font-size:.85rem; font-weight:600; margin:12px 0 4px; }
        form.stack input[type=text], form.stack input[type=email], form.stack input[type=password], form.stack input[type=tel], form.stack textarea {
            width:100%; padding:10px 12px; border:1px solid var(--line); border-radius:8px; background:var(--card); color:var(--ink); font:inherit; }
        .alert { border-radius:8px; padding:10px 14px; margin:0 0 16px; font-size:.92rem; }
        .alert.ok { background:#dcfce7; color:#166534; } .alert.err { background:#fee2e2; color:#991b1b; }
        .panel { background:var(--card); border:1px solid var(--line); border-radius:12px; padding:20px; }
        .narrow { max-width:460px; margin:0 auto; }
        .gallery { display:grid; grid-template-columns:2fr 1fr; gap:8px; } .gallery img { width:100%; border-radius:10px; object-fit:cover; }
        .gallery .main { grid-row:span 2; height:100%; max-height:420px; } .gallery .side { height:206px; }
        @media (max-width:640px) { .gallery { grid-template-columns:1fr; } .gallery .main { max-height:260px; } .gallery .side { display:none; } }
        .cols { display:grid; grid-template-columns:2fr 1fr; gap:24px; } @media (max-width:820px) { .cols { grid-template-columns:1fr; } }
        ul.docs { list-style:none; padding:0; margin:0; } ul.docs li { padding:8px 0; border-top:1px solid var(--line); }
        .preview-bar { background:#fef3c7; color:#92400e; font-size:.85rem; padding:8px 16px; text-align:center; border-bottom:1px solid #fcd34d; }
        .hero { background-color:var(--brand); background-size:cover; background-position:center; color:#fff; border-radius:14px; padding:56px 28px; margin:0 0 20px; position:relative; overflow:hidden; }
        .hero::before { content:''; position:absolute; inset:0; background:linear-gradient(90deg, rgba(0,0,0,.55), rgba(0,0,0,.15)); }
        .hero > * { position:relative; } .hero h1 { color:#fff; font-size:2rem; margin-top:0; } .hero p { color:#f3f4f6; max-width:640px; margin-bottom:0; }
        @stack('styles')
        /* The agency-coloured background used wherever there is no photo (home hero, an auction card or lot banner without a picture): the agency's colours,
           a soft glow in its button colour and a faint skyline of rooftops. Nothing here depends on any property or auction. */
        .brand-bg { position:relative; overflow:hidden; background-color:var(--brand);
            background-image: radial-gradient(900px 440px at 88% -12%, color-mix(in srgb, var(--button) 62%, transparent), transparent 62%), radial-gradient(760px 420px at 4% 115%, color-mix(in srgb, var(--button) 34%, transparent), transparent 62%), linear-gradient(135deg, var(--brand), color-mix(in srgb, var(--brand) 68%, #000)); }
        .brand-bg::before { content:''; position:absolute; left:0; right:0; bottom:0; height:34%; pointer-events:none; opacity:.12;
            background:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 640 220'%3E%3Cg fill='white'%3E%3Cpath d='M0 220V150l40-34 40 34v70z'/%3E%3Cpath d='M100 220V120l50-44 50 44v100z'/%3E%3Cpath d='M220 220V160l34-28 34 28v60z'/%3E%3Cpath d='M310 220V100l60-52 60 52v120z'/%3E%3Cpath d='M450 220V140l44-38 44 38v80z'/%3E%3Cpath d='M560 220V165l30-26 30 26v55z'/%3E%3C/g%3E%3C/svg%3E") repeat-x left bottom / auto 100%; }
        .pill { display:inline-flex; align-items:center; gap:9px; text-transform:uppercase; background:#fff; color:#344054; font-size:.75rem; font-weight:800; letter-spacing:.12em; padding:7px 14px; border-radius:99px; }
        .pill i { width:10px; height:10px; border-radius:50%; background:#98a2b3; display:inline-block; }
        .pill.live { color:#b42318; } .pill.live i { background:#e5372b; box-shadow:0 0 0 5px rgba(229,55,43,.2); }
        footer { flex:0 0 auto; margin-top:auto; text-align:center; color:var(--muted); font-size:.8rem; padding:24px 16px 40px; }
    </style>
</head>
<body>
@if($isPreview ?? false)
<div class="preview-bar" data-preview-bar><strong>Preview.</strong> This is how visitors will see this page. Forms and bidding are switched off here. {{ $previewNote ?? '' }}</div>
@endif
<header class="top">
    <div class="bar">
        <a class="brand" href="{{ route('public.auctions.home', $agency->slug) }}">
            @if($branding['logoUrl'])<img src="{{ $branding['logoUrl'] }}" alt="{{ $branding['name'] }}">@endif
            <span>{{ $siteTitle }}</span>
        </a>
        <nav>
            @if($buyer)
                <a href="{{ route('public.auctions.account', $agency->slug) }}">My account</a>
                <form method="POST" action="{{ route('public.auctions.logout', $agency->slug) }}" style="margin:0;">@csrf<button type="submit">Log out</button></form>
            @else
                <a href="{{ route('public.auctions.login', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Log in</a>
                <a class="signup" href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}">Sign up</a>
            @endif
        </nav>
    </div>
</header>
@hasSection('hero')@yield('hero')@endif
<main class="@yield('mainclass')">
    @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @yield('content')
</main>
<footer>
    @if(($footerText ?? '') !== '')<div style="white-space:pre-line;margin-bottom:6px;">{{ $footerText }}</div>@endif
    &copy; {{ date('Y') }} {{ $branding['name'] }}
    @if($contactEmail || $contactPhone) · @if($contactEmail)<a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>@endif @if($contactPhone) {{ $contactPhone }}@endif @endif
</footer>
@if($isPreview ?? false)
<script>
    // Inside the Preview page: move around the preview, never the live site, and keep every form inert.
    (function () {
        var base = @json(url('/auctions/' . $agency->slug));
        var frame = @json(route('corex.auctions.preview.frame'));
        function tell(path) { try { parent.postMessage({ auctionPreviewPath: path }, '*'); } catch (e) {} }
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a') : null;
            if (!a) return;
            var h = a.getAttribute('href') || '';
            if (h === '' || h.charAt(0) === '#') return;
            e.preventDefault();
            if (h.indexOf(base) === 0) {
                var path = h.substring(base.length).split('?')[0].replace(/\/$/, '') || '/';
                location.href = frame + '?path=' + encodeURIComponent(path);
            }
        });
        document.querySelectorAll('form').forEach(function (f) { f.addEventListener('submit', function (e) { e.preventDefault(); }); });
        document.querySelectorAll('form button, form input[type=submit]').forEach(function (b) { b.style.opacity = '.6'; b.style.cursor = 'not-allowed'; });
        tell(@json($previewPath ?? '/'));
    })();
</script>
@endif
</body>
</html>
