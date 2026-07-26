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
            /* ── AT-336 guest restyle: DARK GLASS (concept 3), animated. ──
               Agency tokens aren't injected pre-auth, so pin CoreX brand here and set
               the neutral tokens to values tuned for the frosted DARK card, so every
               shared guest view (auth, demo) reads light-on-dark correctly. */
            :root {
                --brand-default:  #0b2a4a;
                --brand-button:   #0ea5e9;
                --brand-icon:     #38bdf8;
                --brand-sidebar:  #33c4e0;

                --text-primary:   #f1f6fc;
                --text-secondary: #b6c6da;
                --text-muted:     #8ba0b8;
                --surface:        rgba(255, 255, 255, 0.05);
                --surface-2:      rgba(255, 255, 255, 0.05);
                --border:         rgba(255, 255, 255, 0.13);
                --border-hover:   rgba(255, 255, 255, 0.24);
                --ds-crimson:     #f87171;
                --ds-red:         #f87171;
            }

            body { min-height: 100vh; }

            /* ── Full-screen animated dark stage ── */
            .login-shell {
                position: relative; overflow: hidden; min-height: 100vh;
                display: flex; align-items: center; justify-content: center; padding: 2rem 1.25rem;
                background:
                    radial-gradient(70em 46em at 78% -12%, #133a55 0%, transparent 55%),
                    radial-gradient(55em 40em at 8% 112%, #0c2b40 0%, transparent 60%),
                    #060d16;
            }
            /* Vignette */
            .login-shell::after {
                content: ""; position: absolute; inset: 0; pointer-events: none;
                background: radial-gradient(120% 120% at 50% 45%, transparent 58%, rgba(0,0,0,.55) 100%);
            }

            /* Drifting aurora glow */
            .bg-orb { position: absolute; border-radius: 50%; filter: blur(90px); opacity: .5; pointer-events: none; will-change: transform; }
            .bg-orb.o1 { width: 34rem; height: 34rem; top: -10rem; left: -8rem;  background: radial-gradient(circle, #22d3ee 0%, transparent 68%); animation: drift1 20s ease-in-out infinite; }
            .bg-orb.o2 { width: 28rem; height: 28rem; bottom: -9rem; right: -6rem; background: radial-gradient(circle, #0ea5e9 0%, transparent 68%); animation: drift2 26s ease-in-out infinite; }
            .bg-orb.o3 { width: 22rem; height: 22rem; top: 46%; left: 40%; background: radial-gradient(circle, #7c3aed 0%, transparent 70%); opacity: .32; animation: drift3 32s ease-in-out infinite; }
            @keyframes drift1 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(4rem,3rem) scale(1.14); } }
            @keyframes drift2 { 0%,100% { transform: translate(0,0) scale(1); } 50% { transform: translate(-3.5rem,-2.5rem) scale(1.1); } }
            @keyframes drift3 { 0%,100% { transform: translate(0,0); } 50% { transform: translate(2.5rem,-2rem) scale(1.2); } }

            /* Breathing halo behind the card */
            .bg-halo {
                position: absolute; width: 40rem; height: 40rem; border-radius: 50%; pointer-events: none;
                top: 50%; left: 50%; margin: -20rem 0 0 -20rem;
                background: radial-gradient(circle, rgba(56,189,248,.28) 0%, transparent 62%);
                filter: blur(30px); animation: halo 6s ease-in-out infinite;
            }
            @keyframes halo { 0%,100% { opacity: .55; transform: scale(1); } 50% { opacity: .9; transform: scale(1.08); } }

            /* ── Centred content ── */
            .login-stack {
                position: relative; z-index: 1; width: 100%; max-width: max({{ $maxWidth }}, 480px);
                display: flex; flex-direction: column; align-items: center;
                animation: rise 750ms cubic-bezier(.22,.61,.36,1) both;
            }
            @keyframes rise { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: none; } }

            .login-word { display: flex; align-items: center; gap: .625rem; margin-bottom: 1.35rem; }
            .login-word .tub { width: 36px; height: 36px; border-radius: 12px; display: inline-flex; align-items: center; justify-content: center;
                               background: rgba(56,189,248,.16); box-shadow: inset 0 0 0 1px rgba(56,189,248,.4); }
            .login-word .tub span { width: 16px; height: 16px; border-radius: 5px; background: var(--brand-icon); display: block; }
            .login-word .txt { font-size: 2.7rem; font-weight: 800; letter-spacing: -.03em; color: #eaf2fb; }
            .login-word .txt b { color: var(--brand-icon); font-weight: 800; }

            /* Shimmer — a light glint sweeps the wordmark, then rests. Each part
               carries its own base/highlight pair so "Os" keeps the brand cyan.
               @supports-guarded: a browser without background-clip:text keeps the
               solid colours above instead of painting the text transparent. */
            @supports ((-webkit-background-clip: text) or (background-clip: text)) {
                .login-word .txt   { --shimmer-base: #eaf2fb;          --shimmer-hi: #ffffff; }
                .login-word .txt b { --shimmer-base: var(--brand-icon); --shimmer-hi: #b8f0ff; }
                .login-word .txt,
                .login-word .txt b {
                    background-image: linear-gradient(100deg,
                        var(--shimmer-base) 42%, var(--shimmer-hi) 50%, var(--shimmer-base) 58%);
                    background-size: 300% 100%;
                    background-position: 0% 50%;
                    background-repeat: no-repeat;
                    -webkit-background-clip: text; background-clip: text;
                    -webkit-text-fill-color: transparent;
                    animation: wordShimmer 6s ease-in-out infinite;
                }
            }
            @keyframes wordShimmer {
                0%   { background-position: 100% 50%; }
                40%  { background-position: 0% 50%; }
                100% { background-position: 0% 50%; }
            }

            /* ── Frosted dark glass card ── */
            .login-card {
                width: 100%;
                background: linear-gradient(180deg, rgba(255,255,255,.07), rgba(255,255,255,.03));
                border: 1px solid rgba(255,255,255,.12);
                border-radius: 16px;
                backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px);
                box-shadow: 0 30px 60px -20px rgba(0,0,0,.7), inset 0 1px 0 rgba(255,255,255,.06);
            }
            .login-card label { color: var(--text-secondary); font-size: .75rem; font-weight: 500; }
            .login-card input[type="email"], .login-card input[type="password"], .login-card input[type="text"] {
                background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.13); color: #eef4fb; border-radius: 9px;
                padding: 0.72rem 0.9rem; font-size: 0.925rem;
                transition: border-color 150ms, box-shadow 150ms, background 150ms;
            }
            .login-card input::placeholder { color: #6f8398; }
            .login-card input[type="email"]:focus, .login-card input[type="password"]:focus, .login-card input[type="text"]:focus {
                border-color: var(--brand-icon); box-shadow: 0 0 0 3px rgba(56,189,248,.22);
                outline: none; background: rgba(255,255,255,.08);
            }
            .login-card input[type="checkbox"] { accent-color: var(--brand-icon); }
            .login-card .remember-label { color: var(--text-secondary); }
            .login-card .forgot-link { color: var(--brand-icon); font-size: .75rem; font-weight: 500; text-decoration: none; transition: color 150ms; }
            .login-card .forgot-link:hover { color: #7dd3fc; }
            .login-card .error-text { color: var(--ds-crimson); font-size: .75rem; }
            .login-card .session-status { color: #34d399; font-size: .75rem; margin-bottom: 1rem; }
            /* White CTA */
            .login-card .corex-btn-primary {
                background: #ffffff; color: #0b2a4a; font-weight: 700;
                box-shadow: 0 8px 22px -8px rgba(0,0,0,.5);
            }
            .login-card .corex-btn-primary:hover {
                background: #ffffff;
                box-shadow: 0 0 0 3px rgba(56,189,248,.3), 0 8px 30px -4px rgba(56,189,248,.65);
            }

            .login-foot { position: relative; z-index: 1; margin-top: 1.75rem; text-align: center; color: rgba(255,255,255,.32); font-size: .6875rem; }

            @media (prefers-reduced-motion: reduce) {
                .bg-orb, .bg-halo, .login-stack { animation: none !important; }
                /* Parks at background-position 0% 50% — the flat base colour. */
                .login-word .txt, .login-word .txt b { animation: none !important; }
            }
        </style>
    </head>
    <body class="font-sans antialiased">
        <div class="login-shell">
            {{-- animated backdrop --}}
            <div class="bg-orb o1"></div>
            <div class="bg-orb o2"></div>
            <div class="bg-orb o3"></div>
            <div class="bg-halo"></div>

            {{-- centred content --}}
            <div class="login-stack">
                <div class="login-word">
                    <span class="txt">CoreX <b>Os</b></span>
                </div>

                <div class="login-card" style="padding: 2.75rem 2.5rem;">
                    @if($heading)
                    <div class="mb-6" style="font-size:1.25rem; font-weight:700; letter-spacing:-0.02em; color:var(--text-primary);">
                        {{ $heading }}
                    </div>
                    @endif

                    {{ $slot }}
                </div>

                <div class="login-foot">&copy; {{ date('Y') }} CoreX OS. All rights reserved.</div>
            </div>
        </div>
    </body>
</html>
