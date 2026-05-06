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

<div class="{{ $sticky ? 'sticky top-0 z-30' : '' }} {{ $flush ? '' : '-mx-4 -mt-4 mb-4 lg:-mx-6 lg:-mt-6 lg:mb-6' }}"
     style="background:var(--surface, #fff); border-bottom:1px solid var(--border, #e5e7eb); box-shadow:0 1px 2px rgba(0,0,0,0.05);">
    <div class="flex items-center justify-between h-14 px-4 sm:px-6 lg:px-8">
        {{-- Left: back button + title --}}
        <div class="flex items-center gap-3 min-w-0">
            @if($backRoute)
            <a href="{{ $backRoute }}" class="inline-flex items-center gap-1 text-sm flex-shrink-0" style="color:var(--text-muted, #6b7280);">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                {{ $backLabel }}
            </a>
            <span class="flex-shrink-0" style="color:var(--border, #d1d5db);">|</span>
            @endif
            <h1 class="text-xl font-bold truncate" style="color:var(--text-primary);">{{ $title }}</h1>
        </div>

        {{-- Right: action buttons --}}
        @if(isset($actions))
        <div class="flex items-center gap-2 flex-shrink-0 ml-4">
            {{ $actions }}
        </div>
        @endif
    </div>
</div>
