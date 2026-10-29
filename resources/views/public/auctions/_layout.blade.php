<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $siteTitle) — {{ $siteTitle }}</title>
    <meta name="robots" content="index,follow">
    <style>
        :root { --brand: {{ $branding['colors']['default'] }}; --button: {{ $branding['colors']['button'] }}; --ink:#1f2937; --muted:#6b7280; --line:#e5e7eb; --bg:#f6f7f9; --card:#fff; }
        @media (prefers-color-scheme: dark) { :root { --ink:#e5e7eb; --muted:#9ca3af; --line:#374151; --bg:#111827; --card:#1f2937; } }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif; }
        a { color: var(--button); }
        header.top { background:var(--brand); color:#fff; }
        .bar { max-width:1080px; margin:0 auto; padding:14px 16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        .bar .brand { display:flex; align-items:center; gap:12px; color:#fff; text-decoration:none; font-weight:700; font-size:1.1rem; }
        .bar img { height:40px; width:auto; border-radius:4px; background:#fff; padding:2px; }
        .bar nav { margin-left:auto; display:flex; gap:14px; align-items:center; flex-wrap:wrap; font-size:.9rem; }
        .bar nav a, .bar nav button { color:#fff; text-decoration:none; background:none; border:0; cursor:pointer; font:inherit; padding:0; }
        main { max-width:1080px; margin:0 auto; padding:20px 16px 48px; }
        h1 { font-size:1.6rem; margin:.2rem 0 .4rem; } h2 { font-size:1.15rem; margin:1.6rem 0 .6rem; }
        .muted { color:var(--muted); } .small { font-size:.85rem; }
        .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(250px,1fr)); gap:16px; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:12px; overflow:hidden; }
        .card .pad { padding:14px 16px; }
        .card img.cover { width:100%; height:170px; object-fit:cover; display:block; background:var(--line); }
        .badge { display:inline-block; font-size:.75rem; font-weight:600; padding:2px 9px; border-radius:99px; background:var(--line); color:var(--ink); }
        .badge.ok { background:#dcfce7; color:#166534; } .badge.warn { background:#fef3c7; color:#92400e; } .badge.bad { background:#fee2e2; color:#991b1b; }
        .btn { display:inline-block; background:var(--button); color:#fff !important; border:0; border-radius:8px; padding:10px 18px; font:inherit; font-weight:600; cursor:pointer; text-decoration:none; }
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
        footer { text-align:center; color:var(--muted); font-size:.8rem; padding:24px 16px 40px; }
    </style>
</head>
<body>
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
                <a href="{{ route('public.auctions.signup', $agency->slug) }}?back={{ urlencode(url()->current()) }}" style="border:1px solid #fff;border-radius:8px;padding:6px 12px;">Sign up</a>
            @endif
        </nav>
    </div>
</header>
<main>
    @if(session('status'))<div class="alert ok">{{ session('status') }}</div>@endif
    @if($errors->any())<div class="alert err">@foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>@endif
    @yield('content')
</main>
<footer>
    &copy; {{ date('Y') }} {{ $branding['name'] }}
    @if($contactEmail || $contactPhone) · @if($contactEmail)<a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>@endif @if($contactPhone) {{ $contactPhone }}@endif @endif
</footer>
</body>
</html>
