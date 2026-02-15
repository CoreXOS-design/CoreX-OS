@extends('layouts.nexus')

@section('nexus-content')
    {{-- Page Heading (optional) --}}
    @isset($header)
        <div class="mb-4 rounded-xl bg-[#0b2a4a] px-6 py-4">
            <div class="hfc-onblue-strong">
                {{ $header }}
            </div>
        </div>
    @endisset

    {{-- Agency Tracker: sidebar + content --}}
    <div class="agency-tracker-shell">
        <aside x-data="{ collapsed: false }"
               :class="collapsed ? 'w-20' : 'w-72'"
               class="agency-tracker-sidebar hidden lg:block shrink-0 transition-all duration-200">
            @include('layouts.sidebar')
        </aside>

        <main class="agency-tracker-content">
            <div class="hfc-card p-4 sm:p-6">
                @hasSection('content')
                    @yield('content')
                @else
                    {{ $slot ?? '' }}
                @endif
            </div>
        </main>
    </div>
@endsection
