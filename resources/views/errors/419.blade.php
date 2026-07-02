{{--
    419 — Page Expired (CSRF / session token mismatch).

    Backstop only: bootstrap/app.php intercepts HttpException(419) and soft-bounces
    browser POSTs (guest → login, authed → dashboard) and returns JSON to fetch
    callers, so this view should rarely render. It exists so that any 419 the
    redirect does not catch still lands on a friendly, on-brand page — never the
    raw default "419 Page Expired" dead-end. Reloading fetches a fresh CSRF token.
--}}
@auth
@extends('layouts.corex')

@section('corex-content')
<div class="flex flex-col items-center justify-center py-20 text-center">
    <div class="text-6xl font-bold mb-2" style="color:var(--text-muted); opacity:0.3;">419</div>
    <h1 class="text-lg font-semibold mb-2" style="color:var(--text-primary);">Your session expired</h1>
    <p class="text-sm mb-6" style="color:var(--text-muted); max-width:28rem;">
        For your security, this page was open long enough that its session token expired.
        Nothing was lost — reload to refresh it, or head back to your dashboard and try again.
    </p>
    <div class="flex gap-3">
        <a href="{{ url()->previous() }}" class="text-xs px-4 py-2 rounded-md no-underline" style="background:var(--surface-2); color:var(--text-secondary); border:1px solid var(--border);">Reload &amp; retry</a>
        <a href="{{ route('corex.dashboard') }}" class="text-xs px-4 py-2 rounded-md no-underline" style="background:var(--brand-button); color:#fff;">Dashboard</a>
    </div>
</div>
@endsection
@else
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>419 — Session Expired</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --navy: #0f172a; --navy-2: #1e293b; --border: #334155; --teal: #14b8a6; --teal-hover: #2dd4bf; --text: #e2e8f0; --muted: #64748b; }
        * { box-sizing: border-box; }
        body { font-family: 'Figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: var(--navy); color: var(--text); display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; padding: 1.5rem; }
        .box { text-align: center; max-width: 30rem; }
        .code { font-size: 4rem; font-weight: 700; color: var(--border); line-height: 1; }
        h1 { font-size: 1.25rem; font-weight: 600; margin: 0.75rem 0 0.5rem; }
        p { font-size: 0.9rem; color: var(--muted); margin: 0 0 1.75rem; line-height: 1.5; }
        .actions { display: flex; gap: 0.75rem; justify-content: center; flex-wrap: wrap; }
        a.btn { font-size: 0.85rem; font-weight: 500; padding: 0.6rem 1.15rem; border-radius: 0.5rem; text-decoration: none; display: inline-block; }
        a.ghost { background: var(--navy-2); color: var(--text); border: 1px solid var(--border); }
        a.ghost:hover { border-color: var(--teal); }
        a.solid { background: var(--teal); color: #05201c; }
        a.solid:hover { background: var(--teal-hover); }
    </style>
</head>
<body>
    <div class="box">
        <div class="code">419</div>
        <h1>Your session expired</h1>
        <p>For your security, this page was open long enough that its session token expired. Nothing was lost — sign in again to continue.</p>
        <div class="actions">
            <a class="btn ghost" href="javascript:location.reload()">Reload page</a>
            <a class="btn solid" href="{{ route('login') }}">Go to sign in</a>
        </div>
    </div>
</body>
</html>
@endauth
