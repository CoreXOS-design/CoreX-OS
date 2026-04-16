<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $agency->name ?? 'Onboarding' }} — Property Review</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|jetbrains-mono:400,500,600&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])

    <style>
        :root {
            --brand-sidebar: {{ $agency->sidebar_color ?? '#0ea5e9' }};
            --brand-icon:    {{ $agency->icon_color ?? '#0ea5e9' }};
            --brand-default: {{ $agency->default_color ?? '#0b2a4a' }};
            --brand-button:  {{ $agency->button_color ?? '#0ea5e9' }};
        }
        body { background: var(--bg, #f8fafc); color: var(--text-primary, #0f172a); font-family: 'Inter', system-ui, sans-serif; }
        .portal-header { background: var(--brand-default); color: #fff; }
        .portal-cta { background: var(--brand-button); color: #fff; }
        .portal-cta:hover { filter: brightness(1.05); }
        .portal-accent { color: var(--brand-icon); }
        [x-cloak] { display: none !important; }
    </style>
</head>
<body class="antialiased">

<div class="min-h-screen flex flex-col">
    <header class="portal-header">
        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                @if (!empty($agency->logo_path))
                    <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" class="h-10 w-10 rounded-md bg-white object-contain p-1">
                @else
                    <div class="h-10 w-10 rounded-md bg-white/10 flex items-center justify-center text-lg font-bold">
                        {{ strtoupper(mb_substr($agency->name ?? '?', 0, 1)) }}
                    </div>
                @endif
                <div class="min-w-0">
                    <div class="text-lg font-semibold truncate">{{ $agency->name }}</div>
                    <div class="text-xs opacity-70 truncate">{{ $portal->label ?? 'Property onboarding review' }}</div>
                </div>
            </div>
            @isset($counts)
                <div class="text-xs sm:text-sm opacity-80 whitespace-nowrap">
                    <span class="font-semibold text-white">{{ $counts['confirmed'] }}</span> of
                    <span class="font-semibold text-white">{{ $counts['total'] }}</span> confirmed
                </div>
            @endisset
        </div>
    </header>

    <main class="flex-1">
        @yield('portal-content')
    </main>

    <footer class="border-t border-black/5 py-4 mt-8">
        <div class="max-w-[1400px] mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between text-xs text-muted">
            <div>Powered by <span class="font-semibold">CoreX <span class="portal-accent">OS</span></span></div>
            <div>© {{ date('Y') }} Home Finders Coastal</div>
        </div>
    </footer>
</div>

</body>
</html>
