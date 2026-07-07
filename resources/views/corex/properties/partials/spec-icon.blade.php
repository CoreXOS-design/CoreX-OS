{{-- Lucide-style stroked spec icons for the property preview spec strip.
     Expects: $icon (bed|bath|car|ruler|land). Inherits colour via currentColor. --}}
@switch($icon)
    @case('bed')
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M2 4v16M2 10h18a2 2 0 0 1 2 2v8M2 14h20M6 10V8a2 2 0 0 1 2-2h3a2 2 0 0 1 2 2v2"/></svg>
        @break
    @case('bath')
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12V5a2 2 0 0 1 2-2c1 0 1.5.5 2 1M4 12h16a1 1 0 0 1 1 1v2a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4v-2a1 1 0 0 1 1-1ZM6 19l-1 2M18 19l1 2M8 6h.01"/></svg>
        @break
    @case('car')
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l1.5-4.5A2 2 0 0 1 8.4 7h7.2a2 2 0 0 1 1.9 1.5L19 13m-14 0h14m-14 0a2 2 0 0 0-2 2v3h2m14-5a2 2 0 0 1 2 2v3h-2m-2 0H7m10 0v1m-10-1v1M7 16h.01M17 16h.01"/></svg>
        @break
    @case('ruler')
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16 16 4l4 4L8 20l-4-4Zm3-3 1.5 1.5M10 10l1.5 1.5M13 7l1.5 1.5"/></svg>
        @break
    @case('land')
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18v16H3zM3 4l4 4M21 4l-4 4M3 20l4-4M21 20l-4-4M9 12h6M12 9v6"/></svg>
        @break
    @default
        <svg class="h-5 w-5" fill="none" stroke="currentColor" stroke-width="1.6" viewBox="0 0 24 24"><circle cx="12" cy="12" r="8"/></svg>
@endswitch
