<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <meta name="corex-auth" content="{{ auth()->check() ? '1' : '0' }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('images/favicon.png') }}?v=4">
        <link rel="icon" type="image/x-icon" href="{{ asset('favicon.ico') }}?v=4">
        <link rel="shortcut icon" href="{{ asset('favicon.ico') }}?v=4">

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">

        <!-- Theme init: apply dark class before paint to prevent flash -->
        <script>
            (function(){
                var authed = {{ auth()->check() ? 'true' : 'false' }};
                var dbTheme = '{{ auth()->check() ? (auth()->user()->theme ?? 'dark') : 'dark' }}';
                // When authenticated, the user's DB record is authoritative — never let a
                // previous user's localStorage value bleed into this session.
                var theme = authed ? dbTheme : (localStorage.getItem('corex-theme') || dbTheme);
                if(theme === 'dark'){
                    document.documentElement.classList.add('dark');
                }
                localStorage.setItem('corex-theme', theme);
            })();
        </script>

        <!-- x-cloak: inline so it works before Vite CSS loads -->
        <style>[x-cloak] { display: none !important; }</style>
        <!-- html2canvas-pro: modern CSS color function support (color(), oklch(), color-mix()) -->
        <script src="https://cdn.jsdelivr.net/npm/html2canvas-pro@1.5.8/dist/html2canvas-pro.min.js" defer></script>
        <!-- Scripts & Styles (Alpine.js bundled via Vite — no external CDN) -->
        @vite(['resources/css/app.css', 'resources/css/corex.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/css/paye-fix.css">

        @stack('head')

        {{-- Agency brand colours — MUST be last in <head> to override any bundled CSS --}}
        @auth
        @php
            $_agencyId = auth()->user()?->effectiveAgencyId();
            $_agency   = $_agencyId ? \App\Models\Agency::find($_agencyId) : \App\Models\Agency::first();
        @endphp
        <!-- DEBUG: agencyId={{ $_agencyId ?? 'NULL' }} agencyName={{ $_agency->name ?? 'NONE' }} sidebar={{ $_agency->sidebar_color ?? 'NULL' }} icon={{ $_agency->icon_color ?? 'NULL' }} default={{ $_agency->default_color ?? 'NULL' }} button={{ $_agency->button_color ?? 'NULL' }} -->
        @if($_agency)
        <style id="agency-brand">
            :root,
            :root[data-theme="dark"],
            :root[data-theme="light"],
            html,
            html.dark {
                --brand-sidebar: {{ $_agency->sidebar_color ?? '#0ea5e9' }} !important;
                --brand-icon:    {{ $_agency->icon_color    ?? '#0ea5e9' }} !important;
                --brand-default: {{ $_agency->default_color ?? '#0b2a4a' }} !important;
                --brand-button:  {{ $_agency->button_color  ?? '#0ea5e9' }} !important;
            }
        </style>
        @endif
        @endauth
    </head>
    <body class="font-sans antialiased">
        {{-- Environment column: thin env banner (or nothing on live) above the
             full app. Banner is flex:0 0 auto; the app fills the rest — content
             is pushed down, never overlapped. Empty banner = single flex child
             = exactly 100vh = identical to before (zero layout shift on live). --}}
        <div class="h-screen flex flex-col overflow-hidden">
        @include('partials._env-banner')
        {{-- Mobile sidebar toggle --}}
        <div x-data="{ sidebarOpen: false }" class="flex flex-1 min-h-0 overflow-hidden" style="background:var(--bg)">

            {{-- Mobile overlay --}}
            <div x-show="sidebarOpen" x-transition:enter="transition-opacity ease-linear duration-200"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 x-transition:leave="transition-opacity ease-linear duration-200"
                 x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
                 @click="sidebarOpen = false"
                 class="fixed inset-0 bg-black/50 z-40 lg:hidden" x-cloak></div>

            {{-- Sidebar — fixed 240px (w-60) to match layouts.corex-app. NOTE: an
                 element may only carry ONE :class attribute; a second is dropped by
                 the HTML parser. The previous markup had two, so the width binding
                 (lg:w-60) was silently discarded and the sidebar rendered at content
                 width (wider). Width is now in the static class so it always applies. --}}
            <aside :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
                   class="fixed inset-y-0 left-0 z-50 w-60 transform transition-transform duration-200 ease-in-out lg:relative lg:translate-x-0 lg:flex-shrink-0">
                @include('layouts.corex-sidebar')
            </aside>

            {{-- Main area --}}
            <div class="flex-1 flex flex-col overflow-hidden min-w-0">
                {{-- Mobile header --}}
                <div class="corex-mobile-bar flex items-center lg:hidden px-4 py-2">
                    <button @click="sidebarOpen = true" type="button" style="color:var(--text-secondary)">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <span class="ml-3 text-sm font-bold" style="color:var(--text-primary)">CoreX <span style="color:var(--accent)">Os</span></span>
                </div>

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
        </div>{{-- /environment column --}}

        {{-- Global toast notifications --}}
        <x-toast-notifications />

        {{-- Combined Help Widget (Ellie + Feedback) — header-mounted panel --}}
        @auth
            @include('layouts.partials.help-widget')
        @endauth

        {{-- Interactive help-tour engine (driver.js) — renders only on pages
             with a registered tour. See App\Support\Tours\TourRegistry. --}}
        @include('layouts.partials.tour-engine')

        {{-- Portal Leads real-time toast (P24 + PP). Spec: .ai/specs/portal-leads.md --}}
        @include('components.portal-lead-toast')
        @include('components.reminder-toast')

        @stack('scripts')
    </body>
</html>

