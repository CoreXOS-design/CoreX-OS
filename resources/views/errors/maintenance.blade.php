{{--
    Branded maintenance / down-page (AT-93).

    DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20
    (brand tokens referenced with hardcoded fallbacks per §5.10 — this page
     MUST render with zero app dependencies: no layout, no Vite bundle, no DB,
     no fonts beyond a system stack. It is shown precisely when the app may be
     stressed, so everything it needs is inline.)
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>CoreX OS — Down for maintenance</title>
    <style>
        :root {
            --navy: #0b2a4a;
            --navy-2: #0a2238;
            --teal: #0ea5e9;
            --ink: #e8eef6;
            --muted: rgba(232, 238, 246, 0.62);
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background: radial-gradient(1200px 600px at 50% -10%, var(--navy) 0%, var(--navy-2) 60%, #07192a 100%);
            color: var(--ink);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%;
            max-width: 560px;
            text-align: center;
        }
        .mark {
            width: 64px;
            height: 64px;
            margin: 0 auto 28px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 16px;
            background: linear-gradient(135deg, var(--teal), #0b6fb0);
            box-shadow: 0 12px 36px rgba(14, 165, 233, 0.28);
        }
        .mark svg { width: 34px; height: 34px; color: #fff; }
        .wordmark {
            font-size: 14px;
            letter-spacing: 0.32em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 22px;
        }
        h1 {
            font-size: 28px;
            line-height: 1.2;
            margin: 0 0 14px;
            font-weight: 700;
        }
        p {
            font-size: 16px;
            line-height: 1.6;
            color: var(--muted);
            margin: 0 auto 8px;
            max-width: 440px;
        }
        .divider {
            width: 56px;
            height: 3px;
            border-radius: 3px;
            background: var(--teal);
            margin: 28px auto 24px;
            opacity: 0.85;
        }
        .owner-link {
            display: inline-block;
            margin-top: 18px;
            font-size: 13px;
            color: var(--muted);
            text-decoration: none;
            border: 1px solid rgba(232, 238, 246, 0.18);
            padding: 9px 16px;
            border-radius: 8px;
            transition: border-color .15s ease, color .15s ease;
        }
        .owner-link:hover { color: var(--ink); border-color: rgba(14, 165, 233, 0.6); }
    </style>
</head>
<body data-page="corex-maintenance">
    <main class="card">
        <div class="mark" aria-hidden="true">
            {{-- Simple wrench / maintenance glyph, inline so it needs no asset. --}}
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                 stroke-linecap="round" stroke-linejoin="round">
                <path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L3 18l3 3 6.3-6.3a4 4 0 0 0 5.4-5.4l-2.5 2.5-2.1-.6-.6-2.1 2.5-2.5z"/>
            </svg>
        </div>

        <div class="wordmark">CoreX&nbsp;OS</div>

        <h1>We&rsquo;re down for maintenance</h1>
        <p>CoreX is being updated to serve you better. We&rsquo;ll be back online shortly — thank you for your patience.</p>

        @php($note = $meta['message'] ?? null)
        @if(!empty($note))
            <p style="margin-top:10px;">{{ $note }}</p>
        @endif

        <div class="divider" aria-hidden="true"></div>

        <p style="font-size:14px;">Home Finders Coastal &middot; KZN South Coast</p>

        {{-- Owners can still sign in to run go-live checks. Login is never
             blocked by the maintenance gate. --}}
        <a class="owner-link" href="{{ url('/login') }}">System Owner? Sign in &rarr;</a>
    </main>
</body>
</html>
