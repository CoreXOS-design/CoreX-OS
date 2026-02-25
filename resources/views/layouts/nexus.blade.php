<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600,700,800&display=swap" rel="stylesheet" />

        <!-- Scripts & Styles -->
        @vite(['resources/css/app.css', 'resources/css/nexus.css', 'resources/js/app.js'])
        <link rel="stylesheet" href="/css/paye-fix.css">

        {{-- Agency brand colours — injects --brand-primary/secondary/tertiary into :root --}}
        @auth
        @php
            $_agencyId = auth()->user()?->effectiveAgencyId();
            $_agency   = $_agencyId ? \App\Models\Agency::find($_agencyId) : \App\Models\Agency::first();
        @endphp
        @if($_agency)
        <style>
            :root {
                --brand-primary:   {{ $_agency->primary_color   ?? '#0b2a4a' }};
                --brand-secondary: {{ $_agency->secondary_color ?? '#00b4d8' }};
                --brand-tertiary:  {{ $_agency->tertiary_color  ?? '#1a4a73' }};
            }
        </style>
        @endif
        @endauth

        @stack('head')
    </head>
    <body class="font-sans antialiased">
        {{-- Mobile sidebar toggle --}}
        <div x-data="{ sidebarOpen: false, sidebarCollapsed: false }" class="flex h-screen overflow-hidden bg-gray-100">

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
                   class="fixed inset-y-0 left-0 z-50 transform transition-all duration-200 ease-in-out lg:relative lg:translate-x-0 lg:flex-shrink-0 overflow-hidden">
                @include('layouts.nexus-sidebar')
            </aside>

            {{-- Main area --}}
            <div class="flex-1 flex flex-col overflow-hidden min-w-0">
                {{-- Header --}}
                <div class="flex items-center lg:hidden px-4 py-2 bg-white border-b border-gray-200">
                    <button @click="sidebarOpen = true" type="button" class="text-gray-500 hover:text-gray-700">
                        <svg class="w-6 h-6" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
                        </svg>
                    </button>
                    <span class="ml-3 text-sm font-bold text-gray-900">nexus <span class="text-[#00b4d8]">os</span></span>
                </div>

                @include('layouts.nexus-header')

                {{-- Content --}}
                <main id="appScroll" class="flex-1 overflow-y-auto bg-gray-100 p-4 lg:p-6">
                    @hasSection('nexus-content')
                        @yield('nexus-content')
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

        {{-- Ellie widget (available on all pages) --}}
        @auth
            @include('layouts.partials.ellie-widget')
        @endauth
    </body>
</html>

