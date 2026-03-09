<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        <!-- Theme init: apply dark class before paint to prevent flash -->
        <script>
            (function(){
                if(localStorage.getItem('corex-theme')==='dark'){
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        <!-- x-cloak: inline so it works before Vite CSS loads -->
        <style>[x-cloak] { display: none !important; }</style>
        <!-- Scripts & Styles (Alpine.js bundled via Vite — no external CDN) -->
        @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/css/paye-fix.css">

        {{-- Agency brand colours — 4 semantic roles injected into :root --}}
        @auth
        @php
            $_agencyId = auth()->user()?->effectiveAgencyId();
            $_agency   = $_agencyId ? \App\Models\Agency::find($_agencyId) : \App\Models\Agency::first();
        @endphp
        @if($_agency)
        <style>
            :root {
                --brand-sidebar: {{ $_agency->sidebar_color ?? '#0ea5e9' }};
                --brand-icon:    {{ $_agency->icon_color    ?? '#0ea5e9' }};
                --brand-default: {{ $_agency->default_color ?? '#0b2a4a' }};
                --brand-button:  {{ $_agency->button_color  ?? '#0ea5e9' }};
            }
        </style>
        @endif
        @endauth

        @stack('head')
    </head>
    <body class="font-sans antialiased">
        {{-- Mobile sidebar toggle --}}
        <div x-data="{ sidebarOpen: false, sidebarCollapsed: false }" class="flex h-screen overflow-hidden" style="background:var(--bg)">

            {{-- Mobile overlay --}}
            <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-200"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/50 z-40 lg:hidden" x-cloak></div>

            {{-- Sidebar --}}
            <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
                   :class="sidebarCollapsed ? 'lg:w-16' : 'lg:w-60'"
                   class="fixed inset-y-0 left-0 z-50 transform transition-all duration-200 ease-in-out lg:relative lg:translate-x-0 lg:flex-shrink-0">
                @include('layouts.corex-sidebar')
            </aside>

            {{-- Main area --}}
            <div class="flex-1 flex flex-col overflow-hidden min-w-0">
                {{-- Header --}}
                <div class="corex-mobile-bar flex items-center lg:hidden px-4 py-2">
                    <button @click="sidebarOpen = true" type="button" style="color:var(--text-secondary)">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <span class="ml-3 text-sm font-bold" style="color:var(--text-primary)">CoreX <span style="color:var(--accent)">Os</span></span>
                </div>

                @include('layouts.corex-header')

                {{-- Content --}}
                <main id="appScroll" class="flex-1 overflow-y-auto p-4 lg:p-6" style="background:var(--bg)">
                    @hasSection('corex-content')
                        @yield('corex-content')
                    @else
                        {{-- Agency Tracker / legacy views: header slot + hfc-card wrapper --}}
                        @isset($header)
                            <div class="mb-4">
                                {{ $header }}
                            </div>
                        @endisset
                        @hasSection('content')
                            <div class="hfc-card p-4 sm:p-6">
                                @yield('content')
                            </div>
                        @else
                            <div class="hfc-card p-4 sm:p-6">
                                {{ $slot ?? '' }}
                            </div>
                        @endif
                    @endif
                </main>
            </div>
        </div>

        {{-- Global toast notifications --}}
        <x-toast-notifications />

        {{-- Ellie widget (available on all pages) --}}
        @auth
            @include('layouts.partials.ellie-widget')
        @endauth

        @stack('scripts')
    </body>
</html>

