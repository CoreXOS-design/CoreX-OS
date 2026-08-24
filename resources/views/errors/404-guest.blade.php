{{--
    Guest 404 — 2026-08-24 (Johan): "even on any 404 to a client it should
    go - Shucks, the x you are looking for is not longer valid - visit
    website / contact company." This is the UNKNOWN-OR-INVALID-TOKEN branch
    of the three-branch policy (.ai/audits/2026-08-24-public-link-resilience-
    audit.md) — status stays 404, but the page is warm, not bare. It reveals
    nothing about whether anything ever existed at this URL: the wording
    below is deliberately generic ("isn't valid any more"), never specific
    ("this list has closed") — that specificity is reserved for routes that
    resolved a real, now-dead record and render their OWN courteous page
    (SellerLinkController, SharedMatchController, PublicPresentationController
    all already do this and never fall through to here).

    NO AGENCY BRANDING HERE, DELIBERATELY. By the time execution reaches this
    generic fallback, route-model binding or an unqualified abort(404) has
    already failed — there is no resolved record left to read an agency_id
    from, and guessing would risk showing the WRONG agency's brand to a
    stranger. Routes that carry an agency slug directly in the URL (e.g.
    PublicAgencyPropertiesController) resolve their own agency-branded 404
    from the controller, before ever reaching this view — same reasoning:
    render it yourself if you have the context, don't invent context here.
    CoreX is going multi-agency; this page must never hardcode any one
    agency's name or colours, including Home Finders Coastal's.

    SPLIT OUT OF errors/404.blade.php ON PURPOSE (not merged inline with an
    @if/@else): Blade's @extends schedules the parent layout independently
    of the surrounding control flow — a conditional "@auth @extends(...)
    @else <raw html> @endauth" leaks the extended layout's content into the
    response even on the branch that never calls @extends (reproduced live:
    a cookie-less guest request got this page's content followed by the full
    authenticated app shell in the same response body). @include, unlike
    @extends, evaluates inline and respects normal control flow — this file
    is included from the guest branch instead of being inlined there.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Page not found — CoreX OS</title>
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
            <h1>Shucks — what you're looking for isn't valid any more</h1>
            <p>This link may have been mistyped, or it's simply no longer active. If someone sent you this link, it's best to reach out to them directly for an up-to-date one.</p>
            <div class="actions">
                <a href="mailto:support@corexos.co.za" class="btn">Contact CoreX support</a>
            </div>
        </div>
    </main>
</body>
</html>
