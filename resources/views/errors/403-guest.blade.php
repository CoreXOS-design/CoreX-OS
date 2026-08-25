{{--
    Guest 403 — 2026-08-24, same reasoning as errors/404-guest.blade.php.
    An unauthenticated visitor only ever reaches a bare 403 via a public
    token-gated route (e.g. a tampered/expired Laravel signed URL) — never
    via an in-app permission check, which requires auth and hits @auth in
    errors/403.blade.php. Same neutral, no-agency-context, reveals-nothing
    wording as 404; a stranger cannot tell from this page whether the link
    was ever valid.

    SPLIT OUT ON PURPOSE, not inlined in an @if/@else — see the comment in
    errors/404-guest.blade.php: @extends schedules independently of its
    surrounding conditional and leaks the authenticated app shell into a
    guest's response otherwise (reproduced live on the 404 case before this
    fix). @include respects normal control flow; @extends does not.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Link not valid — CoreX OS</title>
    <style>
        :root {
            --brand: #0b2a4a;
            --accent: #33c4e0;
            --text: #1f2937;
            --text-muted: #6b7280;
            --border: #e5e7eb;
            --surface: #ffffff;
            --surface-2: #f9fafb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 0; min-height: 100vh;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text); background: var(--surface-2);
            display: flex; flex-direction: column;
        }
        header.brand-bar {
            background: var(--brand); color: #fff;
            padding: 18px 24px;
        }
        header.brand-bar .logo { font-size: 1.15rem; font-weight: 700; letter-spacing: 0.5px; }
        header.brand-bar .logo .os { color: var(--accent); }
        main {
            flex: 1; display: flex; align-items: center; justify-content: center;
            padding: 32px 20px;
        }
        .card {
            max-width: 420px; width: 100%; text-align: center;
            background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
            padding: 40px 32px;
        }
        .icon {
            width: 56px; height: 56px; border-radius: 999px; margin: 0 auto 20px;
            display: flex; align-items: center; justify-content: center;
            background: color-mix(in srgb, var(--accent) 14%, transparent);
        }
        h1 { font-size: 1.25rem; margin: 0 0 8px; color: var(--text); }
        p { font-size: 0.9rem; color: var(--text-muted); margin: 0 0 28px; line-height: 1.5; }
        .actions { display: flex; flex-direction: column; gap: 10px; }
        a.btn {
            display: inline-flex; align-items: center; justify-content: center;
            border-radius: 8px; padding: 10px 18px; font-size: 0.875rem; font-weight: 600;
            text-decoration: none; border: 1px solid var(--border);
            color: var(--text); background: var(--surface-2);
        }
        a.btn:hover { border-color: var(--accent); color: var(--accent); }
    </style>
</head>
<body>
    <header class="brand-bar">
        <span class="logo">Core<span class="os">X</span></span>
    </header>
    <main>
        <div class="card">
            <div class="icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24" stroke="#33c4e0" stroke-width="1.75">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z" />
                </svg>
            </div>
            <h1>Shucks — this link isn't valid any more</h1>
            <p>This link may have expired or been mistyped. If someone sent you this link, it's best to reach out to them directly for an up-to-date one.</p>
            <div class="actions">
                <a href="mailto:support@corexos.co.za" class="btn">Contact CoreX support</a>
            </div>
        </div>
    </main>
</body>
</html>
