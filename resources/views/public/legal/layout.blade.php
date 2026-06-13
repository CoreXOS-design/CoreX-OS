<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('legal-title') — CoreX OS</title>
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
            margin: 0; padding: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            color: var(--text); background: var(--surface-2);
            line-height: 1.6;
        }
        header.brand-bar {
            background: var(--brand); color: #fff;
            padding: 18px 24px;
            display: flex; align-items: center; gap: 12px;
        }
        header.brand-bar .logo { font-size: 1.15rem; font-weight: 700; letter-spacing: 0.5px; }
        header.brand-bar .logo .os { color: var(--accent); }
        main.legal-content {
            max-width: 760px; margin: 32px auto; padding: 32px;
            background: var(--surface); border: 1px solid var(--border); border-radius: 6px;
        }
        main.legal-content h1 {
            font-size: 1.6rem; margin: 0 0 8px; color: var(--text);
            border-bottom: 2px solid var(--brand); padding-bottom: 8px;
        }
        main.legal-content h2 { font-size: 1.2rem; margin: 28px 0 8px; color: var(--brand); }
        main.legal-content h3 { font-size: 1.0rem; margin: 18px 0 6px; color: var(--text); }
        main.legal-content p  { margin: 0 0 12px; color: var(--text); }
        main.legal-content ul, main.legal-content ol { margin: 0 0 12px; padding-left: 24px; }
        main.legal-content li { margin: 0 0 6px; }
        main.legal-content a { color: var(--accent); }
        main.legal-content strong { color: var(--text); }
        .updated { font-size: 0.85rem; color: var(--text-muted); margin: 0 0 24px; }
        footer.doc-footer {
            max-width: 760px; margin: 0 auto 40px; padding: 0 32px;
            font-size: 0.75rem; color: var(--text-muted); text-align: center;
        }
        footer.doc-footer a { color: var(--text-muted); }
    </style>
</head>
<body>
    <header class="brand-bar">
        <span class="logo">corex<span class="os"> os</span></span>
    </header>

    <main class="legal-content">
        <h1>@yield('legal-title')</h1>
        <p class="updated">Last updated: {{ $lastUpdated }}</p>
        @yield('legal-body')
    </main>

    <footer class="doc-footer">
        CoreX OS · operated by Home Finders Coastal (KwaZulu-Natal South Coast, South Africa)
        · <a href="mailto:{{ $contactEmail }}">{{ $contactEmail }}</a>
    </footer>
</body>
</html>
