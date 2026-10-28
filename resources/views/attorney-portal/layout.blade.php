<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>@yield('title', 'Your deals') - {{ $agencyName ?? 'Your agent' }}</title>
    <style>
        :root { --brand: {{ $brandColor ?? '#0b2a4a' }}; --btn: {{ $buttonColor ?? '#00b4d8' }}; --ink:#111827; --muted:#6b7280; --line:#e5e7eb; --bg:#f4f6fb; }
        * { box-sizing: border-box; }
        body { margin:0; background:var(--bg); color:var(--ink); font:16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        header.top { background:var(--brand); color:#fff; }
        header.top .in { max-width:1040px; margin:0 auto; padding:14px 16px; display:flex; align-items:center; gap:14px; flex-wrap:wrap; }
        header.top img { max-height:36px; background:#fff; border-radius:6px; padding:3px; }
        header.top .agency { font-weight:700; font-size:1.05rem; }
        header.top .who { margin-left:auto; font-size:.9rem; display:flex; align-items:center; gap:12px; }
        header.top button { background:transparent; border:1px solid rgba(255,255,255,.6); color:#fff; border-radius:8px; padding:6px 12px; cursor:pointer; font-size:.85rem; }
        main { max-width:1040px; margin:0 auto; padding:24px 16px 56px; }
        h1 { font-size:1.45rem; margin:0 0 4px; }
        .sub { color:var(--muted); margin:0 0 20px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:14px; padding:20px; margin-bottom:18px; }
        .card h2 { font-size:1.05rem; margin:0 0 12px; }
        table { width:100%; border-collapse:collapse; }
        th, td { text-align:left; padding:10px 8px; border-bottom:1px solid var(--line); vertical-align:top; font-size:.93rem; }
        th { color:var(--muted); font-weight:600; font-size:.8rem; text-transform:uppercase; letter-spacing:.03em; white-space:nowrap; }
        th a { color:inherit; text-decoration:none; }
        tr:last-child td { border-bottom:0; }
        .btn { display:inline-block; background:var(--btn); color:#fff; text-decoration:none; border:0; border-radius:10px; padding:10px 18px; font-weight:600; cursor:pointer; font-size:.95rem; }
        .btn.ghost { background:#fff; color:var(--brand); border:1px solid var(--line); }
        .pill { display:inline-block; padding:2px 10px; border-radius:999px; font-size:.78rem; font-weight:600; background:#e0f2fe; color:#075985; }
        .pill.done { background:#e5e7eb; color:#374151; }
        .pill.bad { background:#fee2e2; color:#991b1b; }
        .muted { color:var(--muted); }
        .notice { background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:10px; padding:10px 14px; margin-bottom:16px; }
        .error { background:#fef2f2; border:1px solid #fecaca; color:#991b1b; border-radius:10px; padding:10px 14px; margin-bottom:16px; }
        .ok { background:#f0fdf4; border:1px solid #bbf7d0; color:#166534; border-radius:10px; padding:10px 14px; margin-bottom:16px; }
        form.filters { display:flex; gap:10px; flex-wrap:wrap; align-items:end; margin-bottom:16px; }
        form.filters label { font-size:.78rem; color:var(--muted); display:block; }
        form.filters input, form.filters select { padding:8px 10px; border:1px solid var(--line); border-radius:8px; font-size:.93rem; background:#fff; }
        .pager { display:flex; gap:8px; justify-content:center; margin-top:16px; flex-wrap:wrap; }
        .pager a, .pager span { padding:6px 12px; border:1px solid var(--line); border-radius:8px; text-decoration:none; color:var(--brand); background:#fff; font-size:.9rem; }
        .pager span.cur { background:var(--brand); color:#fff; border-color:var(--brand); }
        dl.facts { display:grid; grid-template-columns:max-content 1fr; gap:6px 18px; margin:0; }
        dl.facts dt { color:var(--muted); } dl.facts dd { margin:0; }
        @media (max-width:640px) { .hide-sm { display:none; } th, td { padding:8px 4px; } }
    </style>
</head>
<body>
<header class="top">
    <div class="in">
        @if(!empty($agencyLogo)) <img src="{{ $agencyLogo }}" alt=""> @endif
        <span class="agency">{{ $agencyName ?? 'Your agent' }}</span>
        @if(!empty($personName))
            <span class="who">
                <span>{{ $personName }}@if(!empty($firmName)) &middot; {{ $firmName }}@endif</span>
                @if(request()->routeIs('attorney.deals', 'attorney.deal'))
                    <form method="POST" action="{{ route('attorney.sign-out') }}">@csrf<button type="submit">Sign out</button></form>
                @endif
            </span>
        @endif
    </div>
</header>
<main>
    @yield('content')
</main>
</body>
</html>
