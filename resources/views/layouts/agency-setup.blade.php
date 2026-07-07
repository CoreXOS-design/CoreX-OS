{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md — standalone branded onboarding shell.
     Brand colours are agency-controlled inline (same pattern as layouts/onboarding-portal.blade.php). --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $agency->name ?? 'Agency' }} — CoreX Setup</title>

    <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=4">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=4">

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
        .setup-header { background: var(--brand-default); color: #fff; }
        .setup-cta { background: var(--brand-button); color: #fff; border: none; cursor: pointer; }
        .setup-cta:hover { filter: brightness(1.05); }
        .setup-cta:disabled { opacity: .55; cursor: not-allowed; }
        .setup-accent { color: var(--brand-icon); }
        .setup-progress-track { background: rgba(255,255,255,.18); }
        .setup-progress-fill { background: #fff; transition: width .35s ease; }
        [x-cloak] { display: none !important; }
    </style>
    @stack('head')
</head>
<body class="antialiased">

<div class="min-h-screen flex flex-col">
    <header class="setup-header">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 py-4 flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 min-w-0">
                @if (!empty($agency?->logo_path))
                    <img src="{{ asset('storage/' . $agency->logo_path) }}" alt="{{ $agency->name }}" class="h-10 w-10 rounded-md bg-white object-contain p-1">
                @else
                    <div class="h-10 w-10 rounded-md bg-white/10 flex items-center justify-center text-lg font-bold">
                        {{ strtoupper(mb_substr($agency->name ?? 'C', 0, 1)) }}
                    </div>
                @endif
                <div class="min-w-0">
                    <div class="text-lg font-semibold truncate">{{ $agency->name ?? 'CoreX OS' }}</div>
                    <div class="text-xs opacity-70 truncate">Agency setup</div>
                </div>
            </div>
            @isset($progress)
                <div class="text-xs sm:text-sm opacity-90 whitespace-nowrap">
                    Step {{ $progress['current'] }} of {{ $progress['total'] }} · {{ $progress['percent'] }}%
                </div>
            @endisset
        </div>
        @isset($progress)
            <div class="setup-progress-track h-1 w-full">
                <div class="setup-progress-fill h-1" style="width: {{ $progress['percent'] }}%"></div>
            </div>
        @endisset
    </header>

    <main class="flex-1">
        @yield('setup-content')
    </main>

    <footer class="border-t border-black/5 py-4 mt-8">
        <div class="max-w-[1100px] mx-auto px-4 sm:px-6 lg:px-8 flex items-center justify-between text-xs" style="color:var(--text-muted,#64748b);">
            <div>Powered by <span class="font-semibold">CoreX <span class="setup-accent">OS</span></span></div>
            <div>© {{ date('Y') }} CoreX OS</div>
        </div>
    </footer>
</div>

</body>
</html>
