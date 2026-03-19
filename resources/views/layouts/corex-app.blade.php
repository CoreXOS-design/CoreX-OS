<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>
        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=2">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=2">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=2">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- x-cloak: inline so it works before Vite CSS loads -->
        <style>[x-cloak] { display: none !important; }</style>
        <!-- Scripts & Styles (Alpine.js bundled via Vite — no external CDN) -->
        @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/css/paye-fix.css">
    </head>
    <body class="font-sans antialiased">
        {{-- Mobile sidebar toggle --}}
        <div x-data="{ sidebarOpen: false }" class="flex h-screen overflow-hidden" style="background:var(--bg, #f4f6fb)">

            {{-- Mobile overlay --}}
            <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-200"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/50 z-40 lg:hidden" x-cloak></div>

            {{-- Sidebar --}}
            <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
                   class="fixed inset-y-0 left-0 z-50 w-60 transform transition-transform duration-200 ease-in-out lg:relative lg:translate-x-0 lg:flex-shrink-0">
                @include('layouts.corex-sidebar')
            </aside>

            {{-- Main area --}}
            <div class="flex-1 flex flex-col overflow-hidden min-w-0">
                {{-- Header --}}
                <div class="flex items-center lg:hidden px-4 py-2" style="background:var(--surface, #fff); border-bottom:1px solid var(--border, rgba(0,0,0,0.07))">
                    <button @click="sidebarOpen = true" type="button" style="color:var(--text-secondary, #4b5563)">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <span class="ml-3 text-sm font-bold" style="color:var(--text-primary, #111827)">CoreX <span style="color:var(--brand-icon, #0ea5e9)">Os</span></span>
                </div>

                {{-- Content --}}
                <main class="flex-1 overflow-y-auto p-4 lg:p-6" style="background:var(--bg, #f4f6fb)">
                    @hasSection('corex-content')
                        @yield('corex-content')
                    @else
                        {{ $slot ?? '' }}
                    @endif
                </main>
            </div>
        </div>

        {{-- Ellie widget (available on all pages) --}}
        @auth
            @include('layouts.partials.ellie-widget')
        @endauth
    </body>
</html>
