{{-- Sticky Action Bar Component --}}
{{-- Usage:
    <x-sticky-action-bar>
        <x-slot name="left">
            <a href="/back" class="inline-flex items-center gap-1 text-sm text-gray-600 hover:text-gray-900">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                Back
            </a>
        </x-slot>
        <x-slot name="center">
            <h2 class="text-sm font-semibold text-gray-700 truncate">Page Title</h2>
        </x-slot>
        <x-slot name="right">
            <button class="px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-lg hover:bg-blue-700">Save</button>
        </x-slot>
    </x-sticky-action-bar>
--}}

<div class="sticky top-0 z-50 shadow-sm -mx-4 lg:-mx-6 -mt-4 lg:-mt-6 mb-4 lg:mb-6" style="background: var(--surface); border-bottom: 1px solid var(--border);">
    <div class="px-4 sm:px-6 lg:px-8">
        <div class="flex items-center justify-between h-14">
            {{-- Left side: Back button, breadcrumbs --}}
            <div class="flex items-center gap-3">
                {{ $left ?? '' }}
            </div>

            {{-- Center: Page title or context --}}
            <div class="flex-1 text-center truncate mx-4" style="color: var(--text-primary);">
                {{ $center ?? '' }}
            </div>

            {{-- Right side: Primary actions --}}
            <div class="flex items-center gap-2">
                {{ $right ?? '' }}
            </div>
        </div>
    </div>
</div>
