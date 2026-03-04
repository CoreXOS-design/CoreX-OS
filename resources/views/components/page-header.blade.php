{{--
    Page Header — Sticky action bar with back button, title, and actions.

    Usage:
    <x-page-header
        title="Deal Register"
        :back-route="route('agent.dashboard')"
        back-label="Dashboard"
    >
        <x-slot:actions>
            <button type="button" class="...">Export</button>
        </x-slot:actions>
    </x-page-header>

    Props:
        title       (required)  Page title string
        back-route  (optional)  URL for back arrow link
        back-label  (optional)  Text next to back arrow (default: "Back")
        sticky      (optional)  Whether to stick on scroll (default: true)
        flush       (optional)  When true, omit negative margins (use inside a full-bleed wrapper)

    Slots:
        actions     (optional)  Buttons/links for the right side
--}}

@props([
    'title',
    'backRoute' => null,
    'backLabel' => 'Back',
    'sticky' => true,
    'flush' => false,
])

<div class="{{ $sticky ? 'sticky top-0 z-30' : '' }} bg-white border-b border-gray-200 shadow-sm {{ $flush ? '' : '-mx-4 -mt-4 mb-4 lg:-mx-6 lg:-mt-6 lg:mb-6' }}">
    <div class="flex items-center justify-between h-14 px-4 sm:px-6 lg:px-8">
        {{-- Left: back button + title --}}
        <div class="flex items-center gap-3 min-w-0">
            @if($backRoute)
            <a href="{{ $backRoute }}" class="inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-800 flex-shrink-0">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                {{ $backLabel }}
            </a>
            <span class="text-gray-300 flex-shrink-0">|</span>
            @endif
            <h1 class="text-lg font-semibold text-gray-800 truncate">{{ $title }}</h1>
        </div>

        {{-- Right: action buttons --}}
        @if(isset($actions))
        <div class="flex items-center gap-2 flex-shrink-0 ml-4">
            {{ $actions }}
        </div>
        @endif
    </div>
</div>
