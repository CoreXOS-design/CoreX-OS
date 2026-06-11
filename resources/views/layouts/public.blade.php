<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>@yield('title', config('app.name', 'CoreX OS'))</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=4">
    <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=4">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=4">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700&display=swap" rel="stylesheet">

    {{-- Pulls in CoreX tokens via the same bundles as the guest (login) page. --}}
    @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])

    <style>
        /* Public-page defaults: agency tokens aren't injected on unauthenticated
           pages, so pin CoreX corporate brand values like the guest layout. */
        :root {
            --brand-default: #0b2a4a;
            --brand-button:  #00b4d8;
            --brand-icon:    #33c4e0;
        }
        body {
            margin: 0;
            font-family: 'figtree', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--surface-2, #f5f6fa);
            color: var(--text-primary, #111827);
            -webkit-font-smoothing: antialiased;
            min-height: 100vh;
        }
        .container-public {
            max-width: 640px;
            margin: 0 auto;
            padding: 1.5rem 1rem;
        }
        @media (min-width: 768px) {
            .container-public { padding: 3rem 1.5rem; }
        }
    </style>
    @stack('head')
</head>
<body class="font-sans antialiased">
    <main class="container-public">
        @yield('public-content')
    </main>
@include('public.partials.privacy-footer')
</body>
</html>
