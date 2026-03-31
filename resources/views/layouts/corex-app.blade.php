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

        {{-- Report Issue button + modal --}}
        @auth
        <div x-data="{ reportOpen: false, reportDesc: '', reportSubmitting: false }" class="fixed bottom-4 right-4 z-40" style="pointer-events:none">
            {{-- Trigger link, positioned above Ellie --}}
            <button type="button" @click="reportOpen = true" class="mb-16 text-[11px] text-white/30 hover:text-white/60 transition-colors cursor-pointer" style="pointer-events:auto">
                Report Issue
            </button>

            {{-- Modal --}}
            <template x-teleport="body">
                <div x-show="reportOpen" x-cloak class="fixed inset-0 z-[9999] flex items-center justify-center p-4" style="pointer-events:auto">
                    <div class="fixed inset-0 bg-black/50" @click="reportOpen = false"></div>
                    <div class="relative w-full max-w-md rounded-2xl border border-white/10 bg-[#1a1f2e] p-6 shadow-2xl" @click.stop>
                        <h3 class="text-lg font-semibold text-white mb-4">Report an Issue</h3>
                        <form method="POST" action="{{ route('admin.fault-reports.manual') }}" @submit="reportSubmitting = true">
                            @csrf
                            <input type="hidden" name="url" value="">
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-xs text-white/50 mb-1">Current Page</label>
                                    <input type="text" class="w-full rounded-lg border border-white/10 bg-white/5 text-white/60 text-sm px-3 py-2" readonly
                                           x-init="$el.value = window.location.href; $el.closest('form').querySelector('input[name=url]').value = window.location.href">
                                </div>
                                <div>
                                    <label class="block text-xs text-white/50 mb-1">Describe the issue <span class="text-red-400">*</span></label>
                                    <textarea name="description" x-model="reportDesc" required rows="4"
                                              class="w-full rounded-lg border border-white/10 bg-white/5 text-white text-sm px-3 py-2 placeholder-white/30 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"
                                              placeholder="What happened? What did you expect?"></textarea>
                                </div>
                            </div>
                            <div class="flex items-center justify-end gap-2 mt-4">
                                <button type="button" @click="reportOpen = false" class="px-4 py-2 text-sm text-white/50 hover:text-white/80">Cancel</button>
                                <button type="submit" :disabled="reportSubmitting || !reportDesc.trim()"
                                        class="px-4 py-2 text-sm font-medium rounded-lg bg-blue-600 text-white hover:bg-blue-700 disabled:opacity-40">
                                    <span x-show="!reportSubmitting">Submit Report</span>
                                    <span x-show="reportSubmitting" x-cloak>Submitting...</span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </template>
        </div>
        @endauth

        {{-- Ellie widget (available on all pages) --}}
        @auth
            @include('layouts.partials.ellie-widget')
        @endauth

        {{-- Frontend error capture --}}
        @include('partials.error-reporter')
    </body>
</html>
