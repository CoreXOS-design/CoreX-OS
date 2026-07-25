<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'CoreX OS') }} — Sign In</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=4">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=4">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=4">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])

        <style>
            /* ── AT-336 guest restyle: LIGHT / white auth. ──
               Agency brand tokens aren't injected on unauthenticated pages, so pin the
               CoreX corporate brand here. The card is now WHITE, so the neutral tokens
               resolve to the LIGHT slate palette (matching the app's light theme) rather
               than the previous dark-card overrides. This block loads after corex.css so
               at equal specificity it wins. All shared guest views (auth, demo T&C, gate)
               inherit these, and dark-text-on-white now reads correctly everywhere. */
            :root {
                --brand-default: #0b2a4a;
                --brand-button:  #00b4d8;
                --brand-icon:    #0ea5e9;
                --brand-sidebar: #33c4e0;

                --text-primary:   #0f172a;   /* slate-900 */
                --text-secondary: #334155;   /* slate-700 */
                --text-muted:     #64748b;   /* slate-500 */
                --surface:        #ffffff;
                --surface-2:      #f8fafc;   /* slate-50  */
                --border:         #e2e8f0;   /* slate-200 */
                --border-hover:   #cbd5e1;   /* slate-300 */

                --ds-crimson:     #dc2626;
                --ds-red:         #dc2626;
            }

            body { min-height: 100vh; background: #ffffff; }

            /* ── Split-screen shell ── */
            .login-shell { min-height: 100vh; display: flex; }

            /* Right: white form panel (always shown, centred) */
            .login-form-panel {
                flex: 1 1 0%;
                display: flex;
                flex-direction: column;
                align-items: center;
                justify-content: center;
                padding: 2rem 1.25rem;
                background: var(--surface-2);   /* slate-50 so the white card lifts */
                min-width: 0;
            }

            /* Left: branded, animated panel — fills the empty space (hidden on small screens) */
            .login-brand-panel {
                position: relative;
                overflow: hidden;
                flex: 1 1 0%;
                display: none;
                flex-direction: column;
                justify-content: space-between;
                padding: 3.5rem;
                color: #fff;
                background:
                    radial-gradient(120% 120% at 0% 0%, color-mix(in srgb, var(--brand-default) 80%, #000) 0%, var(--brand-default) 55%, #06182b 100%);
            }
            @media (min-width: 1024px) { .login-brand-panel { display: flex; } }

            /* Drifting aurora orbs (GPU-friendly transform/opacity only) */
            .orb { position: absolute; border-radius: 9999px; filter: blur(70px); opacity: 0.55; pointer-events: none; }
            .orb-1 { width: 460px; height: 460px; top: -120px; left: -80px;
                     background: radial-gradient(circle, var(--brand-button) 0%, transparent 70%); animation: drift1 18s ease-in-out infinite; }
            .orb-2 { width: 380px; height: 380px; bottom: -100px; right: -60px;
                     background: radial-gradient(circle, var(--brand-icon) 0%, transparent 70%); animation: drift2 22s ease-in-out infinite; }
            .orb-3 { width: 300px; height: 300px; top: 40%; left: 30%;
                     background: radial-gradient(circle, #7c3aed 0%, transparent 70%); opacity: 0.35; animation: drift3 26s ease-in-out infinite; }
            @keyframes drift1 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(60px,40px) scale(1.15); } }
            @keyframes drift2 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-50px,-40px) scale(1.1); } }
            @keyframes drift3 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(30px,-30px) scale(1.2); } }

            /* Faint moving grid overlay for texture */
            .brand-grid {
                position: absolute; inset: 0; pointer-events: none; opacity: 0.10;
                background-image:
                    linear-gradient(to right, rgba(255,255,255,0.6) 1px, transparent 1px),
                    linear-gradient(to bottom, rgba(255,255,255,0.6) 1px, transparent 1px);
                background-size: 44px 44px;
                mask-image: radial-gradient(120% 120% at 50% 50%, #000 40%, transparent 100%);
                animation: gridpan 30s linear infinite;
            }
            @keyframes gridpan { from { background-position: 0 0; } to { background-position: 44px 44px; } }

            .brand-content { position: relative; z-index: 1; }
            .brand-logo-tub {
                width: 44px; height: 44px; border-radius: 14px; display: inline-flex; align-items: center; justify-content: center;
                background: color-mix(in srgb, #fff 14%, transparent);
                box-shadow: inset 0 0 0 1px color-mix(in srgb, #fff 30%, transparent);
            }
            .brand-logo-tub span { width: 18px; height: 18px; border-radius: 6px; background: var(--brand-icon); display: block; }
            .brand-features li { display: flex; align-items: center; gap: 0.625rem; }
            .brand-features svg { flex-shrink: 0; }

            @media (prefers-reduced-motion: reduce) {
                .orb, .brand-grid { animation: none !important; }
            }

            /* ── White login card ── */
            .login-card {
                background: var(--surface);
                border: 1px solid var(--border);
                box-shadow: 0 10px 30px -12px rgba(15, 23, 42, 0.12), 0 2px 6px rgba(15, 23, 42, 0.04);
                border-radius: 12px;
            }
            .login-card label { color: var(--text-secondary); font-size: 0.75rem; font-weight: 500; }
            .login-card input[type="email"],
            .login-card input[type="password"],
            .login-card input[type="text"] {
                background: var(--surface-2);
                border: 1px solid var(--border);
                color: var(--text-primary);
                border-radius: 8px;
            }
            .login-card input::placeholder { color: var(--text-muted); }
            .login-card input[type="email"]:focus,
            .login-card input[type="password"]:focus,
            .login-card input[type="text"]:focus {
                border-color: var(--brand-button);
                box-shadow: 0 0 0 3px color-mix(in srgb, var(--brand-button) 18%, transparent);
                outline: none;
                background: #fff;
            }
            .login-card input[type="checkbox"] { accent-color: var(--brand-button); }
            .login-card .remember-label { color: var(--text-secondary); }
            .login-card .forgot-link { color: var(--brand-icon); font-size: 0.75rem; font-weight: 500; text-decoration: none; transition: color 150ms; }
            .login-card .forgot-link:hover { color: var(--brand-button); }
            .login-card .error-text { color: var(--ds-crimson); font-size: 0.75rem; }
            .login-card .session-status { color: #059669; font-size: 0.75rem; margin-bottom: 1rem; }
        </style>
    </head>
    <body class="font-sans antialiased">
        <div class="login-shell">

            {{-- LEFT — branded, animated panel (fills the empty space; lg+ only) --}}
            <aside class="login-brand-panel">
                <div class="orb orb-1"></div>
                <div class="orb orb-2"></div>
                <div class="orb orb-3"></div>
                <div class="brand-grid"></div>

                {{-- Top: wordmark --}}
                <div class="brand-content" style="display:flex; align-items:center; gap:0.75rem;">
                    <span class="brand-logo-tub"><span></span></span>
                    <span style="font-size:1.125rem; font-weight:800; letter-spacing:-0.03em;">CoreX <span style="color:var(--brand-icon);">Os</span></span>
                </div>

                {{-- Middle: headline + features --}}
                <div class="brand-content" style="max-width:30rem;">
                    <h1 style="font-size:2.25rem; line-height:1.1; font-weight:800; letter-spacing:-0.03em; margin-bottom:1rem;">
                        The real estate<br>operating system.
                    </h1>
                    <p style="font-size:0.95rem; line-height:1.6; color:rgba(255,255,255,0.7); margin-bottom:2rem;">
                        Properties, deals, compliance and people — everything your agency runs on, in one place.
                    </p>
                    <ul class="brand-features" style="display:flex; flex-direction:column; gap:0.875rem; font-size:0.875rem; color:rgba(255,255,255,0.85);">
                        <li>
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--brand-icon)" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            One graph linking properties, contacts, deals &amp; agents
                        </li>
                        <li>
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--brand-icon)" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            E-signatures, FICA &amp; portal syndication built in
                        </li>
                        <li>
                            <svg width="18" height="18" fill="none" viewBox="0 0 24 24" stroke="var(--brand-icon)" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                            Built for agents — not for screens
                        </li>
                    </ul>
                </div>

                {{-- Bottom: copyright --}}
                <div class="brand-content" style="font-size:0.6875rem; color:rgba(255,255,255,0.4);">
                    &copy; {{ date('Y') }} CoreX OS. All rights reserved.
                </div>
            </aside>

            {{-- RIGHT — white form panel --}}
            <main class="login-form-panel">
                {{-- Compact wordmark — mobile only (the brand panel carries it on desktop) --}}
                <div class="mb-6 lg:hidden" style="display:flex; align-items:center; gap:0.625rem;">
                    <span style="width:34px; height:34px; border-radius:11px; display:inline-flex; align-items:center; justify-content:center; background:color-mix(in srgb, var(--brand-default) 12%, transparent); box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--brand-default) 25%, transparent);">
                        <span style="width:15px; height:15px; border-radius:5px; background:var(--brand-default); display:block;"></span>
                    </span>
                    <span style="font-size:1.125rem; font-weight:800; letter-spacing:-0.03em; color:var(--text-primary);">CoreX <span style="color:var(--brand-icon);">Os</span></span>
                </div>

                <div class="login-card w-full" style="max-width: {{ $maxWidth }}; padding: 2.25rem 2rem;">
                    @if($heading)
                    <div class="mb-6" style="font-size:1.25rem; font-weight:700; letter-spacing:-0.02em; color:var(--text-primary);">
                        {{ $heading }}
                    </div>
                    @endif

                    {{ $slot }}
                </div>

                <div class="mt-6 text-center lg:hidden" style="color:var(--text-muted); font-size:0.6875rem;">
                    &copy; {{ date('Y') }} CoreX OS. All rights reserved.
                </div>
            </main>

        </div>
    </body>
</html>
