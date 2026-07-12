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
            /* Guest-only brand defaults: agency tokens aren't injected on unauthenticated pages,
               so pin CoreX corporate brand values here. Tokens over hex per UI_DESIGN_SYSTEM §1.4. */
            :root {
                --brand-default: #0b2a4a;
                --brand-button:  #00b4d8;
                --brand-icon:    #33c4e0;
                --brand-sidebar: #33c4e0;

                /* ── THE CARD IS ALWAYS DARK. THE TOKENS MUST BE TOO. ──
                   corex.css declares the LIGHT palette at :root (--text-primary #111827,
                   --surface #ffffff) and only flips to dark under `html.dark`, which a
                   guest page never sets. So every guest view written against the design
                   tokens rendered near-black text on this dark card — invisible. The demo
                   gate and T&C pages use them heavily (16 and 10 references); that is what
                   made half their copy unreadable.

                   Fixed here, at the token layer, rather than by rewriting the colour of
                   every element in four views: any guest page that reaches for a token now
                   gets a value that works on the card it is actually sitting on.

                   NOT done by adding `class="dark"` to <html>: `html.dark` (0,1,1) outranks
                   `:root` (0,1,0), so it would override these card-tuned values with the
                   app-chrome palette — opaque #13161d panels and a near-invisible 6% border
                   — inside a translucent card they were never designed for. It would also
                   switch on every other `html.dark` rule in corex.css across pages that
                   never asked for them. This block loads after corex.css, so at equal
                   specificity it simply wins. */
                --text-primary:   #f9fafb;
                --text-secondary: rgba(255, 255, 255, 0.62);
                --text-muted:     #9ca3af;
                --surface:        rgba(255, 255, 255, 0.06);
                --surface-2:      rgba(255, 255, 255, 0.06);
                --border:         rgba(255, 255, 255, 0.14);
                --border-hover:   rgba(255, 255, 255, 0.24);

                /* Error/alert red must read on dark: #dc2626 on this card is muddy. */
                --ds-crimson:     #f87171;
                --ds-red:         #f87171;
            }

            /* The gate's access-code field is input[type=text]; the card's placeholder rule
               only covered email/password, so its "XXXX-XXXX" hint rendered at the browser
               default — near-black on dark. */
            .login-card input[type="text"]::placeholder,
            .login-card textarea::placeholder {
                color: rgba(255, 255, 255, 0.25);
            }

            body {
                background: linear-gradient(
                    135deg,
                    color-mix(in srgb, var(--brand-default) 85%, #000) 0%,
                    var(--brand-default) 50%,
                    color-mix(in srgb, var(--brand-default) 92%, #fff) 100%
                );
                min-height: 100vh;
            }

            /* Dark card overrides — the card is always dark-themed regardless of user theme. */
            .login-card label {
                color: var(--text-muted, #9ca3af);
                font-size: 0.75rem;
                font-weight: 500;
            }

            .login-card input[type="email"],
            .login-card input[type="password"],
            .login-card input[type="text"] {
                background: rgba(255, 255, 255, 0.06);
                border: 1px solid rgba(255, 255, 255, 0.12);
                color: #f9fafb;
                border-radius: 6px;
            }

            .login-card input[type="email"]::placeholder,
            .login-card input[type="password"]::placeholder {
                color: rgba(255,255,255,0.25);
            }

            .login-card input[type="email"]:focus,
            .login-card input[type="password"]:focus {
                border-color: var(--brand-button);
                box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button) 15%, transparent);
                outline: none;
                background: rgba(255, 255, 255, 0.08);
            }

            .login-card input[type="checkbox"] {
                accent-color: var(--brand-button);
            }

            .login-card .remember-label {
                color: var(--text-muted, #9ca3af);
            }

            .login-card .forgot-link {
                color: var(--brand-icon);
                font-size: 0.75rem;
                font-weight: 500;
                text-decoration: none;
                transition: color 150ms;
            }
            .login-card .forgot-link:hover {
                color: var(--brand-button);
            }

            .login-card .error-text {
                color: #f87171;
                font-size: 0.75rem;
            }

            .login-card .session-status {
                color: #34d399;
                font-size: 0.75rem;
                margin-bottom: 1rem;
            }
        </style>
    </head>
    <body class="font-sans antialiased">

        <div class="min-h-screen flex flex-col items-center justify-center px-4">

            {{-- Branding --}}
            <div class="mb-8 text-center">
                <div style="font-size: 2rem; font-weight: 800; letter-spacing: -0.04em; color: #fff; line-height: 1;">
                    CoreX <span style="color: var(--brand-icon);">Os</span>
                </div>
            </div>

            {{-- Login card. $maxWidth / $heading default to the login form's own values
                 (400px, "Sign in to your account"), so every auth view is unchanged;
                 the demo T&C page opts into a wider card and suppresses the heading. --}}
            <div class="login-card w-full rounded-md" style="max-width: {{ $maxWidth }}; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); padding: 2.25rem 2rem; backdrop-filter: blur(8px);">

                @if ($heading)
                    <div class="text-xs font-medium text-center mb-6" style="color: rgba(255,255,255,0.55); letter-spacing: 0.02em;">
                        {{ $heading }}
                    </div>
                @endif

                {{ $slot }}

            </div>

            {{-- Footer --}}
            <div class="mt-8 text-center" style="color: rgba(255,255,255,0.2); font-size: 0.6875rem;">
                &copy; {{ date('Y') }} CoreX OS. All rights reserved.
            </div>

        </div>

    </body>
</html>
