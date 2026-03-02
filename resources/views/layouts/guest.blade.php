<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'CoreX OS') }} — Sign In</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])

        <style>
            body {
                background: linear-gradient(135deg, #071e35 0%, #0b2a4a 50%, #0d3259 100%);
                min-height: 100vh;
            }

            /* Override Breeze component styles for dark login card */
            .login-card label {
                color: #9ca3af;
                font-size: 0.8125rem;
                font-weight: 500;
            }

            .login-card input[type="email"],
            .login-card input[type="password"],
            .login-card input[type="text"] {
                background: rgba(255, 255, 255, 0.06);
                border: 1px solid rgba(255, 255, 255, 0.12);
                color: #f9fafb;
                border-radius: 0.5rem;
            }

            .login-card input[type="email"]::placeholder,
            .login-card input[type="password"]::placeholder {
                color: rgba(255,255,255,0.25);
            }

            .login-card input[type="email"]:focus,
            .login-card input[type="password"]:focus {
                border-color: #00b4d8;
                box-shadow: 0 0 0 3px rgba(0, 180, 216, 0.15);
                outline: none;
                background: rgba(255, 255, 255, 0.08);
            }

            .login-card input[type="checkbox"] {
                accent-color: #00b4d8;
            }

            .login-card .remember-label {
                color: #9ca3af;
            }

            .login-card .forgot-link {
                color: #33c4e0;
                font-size: 0.8125rem;
            }
            .login-card .forgot-link:hover {
                color: #00b4d8;
            }

            .login-card .login-btn {
                background: #00b4d8;
                color: #fff;
                font-weight: 600;
                letter-spacing: 0.01em;
                border-radius: 0.5rem;
                padding: 0.6rem 1.75rem;
                border: none;
                cursor: pointer;
                transition: background 150ms;
                font-size: 0.9rem;
            }
            .login-card .login-btn:hover {
                background: #0090b0;
            }

            .login-card .error-text {
                color: #f87171;
                font-size: 0.8125rem;
            }

            .login-card .session-status {
                color: #34d399;
                font-size: 0.8125rem;
                margin-bottom: 1rem;
            }
        </style>
    </head>
    <body class="font-sans antialiased">

        <div class="min-h-screen flex flex-col items-center justify-center px-4">

            {{-- Branding --}}
            <div class="mb-8 text-center">
                <div style="font-size: 2rem; font-weight: 800; letter-spacing: -0.04em; color: #fff; line-height: 1;">
                    CoreX <span style="color: #33c4e0;">Os</span>
                </div>
            </div>

            {{-- Login card --}}
            <div class="login-card w-full" style="max-width: 400px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); border-radius: 1rem; padding: 2.25rem 2rem; backdrop-filter: blur(8px);">

                <div style="color: rgba(255,255,255,0.55); font-size: 0.8125rem; font-weight: 500; margin-bottom: 1.5rem; text-align: center; letter-spacing: 0.02em;">
                    Sign in to your account
                </div>

                {{ $slot }}

            </div>

            {{-- Footer --}}
            <div style="margin-top: 2rem; color: rgba(255,255,255,0.2); font-size: 0.7rem; text-align: center;">
                &copy; {{ date('Y') }} Home Finders Coastal. All rights reserved.
            </div>

        </div>

    </body>
</html>
