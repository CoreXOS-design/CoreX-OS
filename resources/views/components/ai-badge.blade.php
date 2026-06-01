@props([
    'label'    => 'AI',
    'tooltip'  => 'Created by AI',
    'size'     => 'sm', // sm | xs
])

@php
    $sizeClasses = $size === 'xs'
        ? 'text-[10px] px-1 py-[1px] gap-[2px]'
        : 'text-[11px] px-1.5 py-[2px] gap-1';
@endphp

<span
    title="{{ $tooltip }}"
    class="inline-flex items-center rounded-md bg-purple-500/15 text-purple-300 border border-purple-500/30 font-medium uppercase tracking-wide {{ $sizeClasses }}"
>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="w-3 h-3" aria-hidden="true">
        <path d="M10 2a1 1 0 0 1 1 1v1.05a6 6 0 0 1 4.95 4.95H17a1 1 0 1 1 0 2h-1.05A6 6 0 0 1 11 15.95V17a1 1 0 1 1-2 0v-1.05A6 6 0 0 1 4.05 11H3a1 1 0 1 1 0-2h1.05A6 6 0 0 1 9 4.05V3a1 1 0 0 1 1-1Zm0 4a4 4 0 1 0 0 8 4 4 0 0 0 0-8Zm0 2a2 2 0 1 1 0 4 2 2 0 0 1 0-4Z"/>
    </svg>
    <span>{{ $label }}</span>
</span>
