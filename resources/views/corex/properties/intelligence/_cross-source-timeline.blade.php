{{--
    Cross-Source Activity Timeline — unified chronological view of every intelligence event
    on this property: presentations, prospecting discoveries, portal price changes,
    contact linkages, buyer match notifications.

    Inputs: $timeline (Collection of stdClass objects with type, icon, label, description, date, url)
--}}

@php
    /** @var \Illuminate\Support\Collection $timeline */

    $iconForType = function (string $iconKey): string {
        return match ($iconKey) {
            'doc' => '<path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/>',
            'globe' => '<path stroke-linecap="round" stroke-linejoin="round" d="M12 21a9.004 9.004 0 0 0 8.716-6.747M12 21a9.004 9.004 0 0 1-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 0 1 7.843 4.582M12 3a8.997 8.997 0 0 0-7.843 4.582m15.686 0A11.953 11.953 0 0 1 12 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0 1 21 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0 1 12 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 0 1 3 12c0-1.605.42-3.113 1.157-4.418"/>',
            'trend' => '<path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18 9 11.25l4.306 4.306a11.95 11.95 0 0 1 5.814-5.518l2.74-1.22m0 0-5.94-2.281m5.94 2.28-2.28 5.941"/>',
            'user' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z"/>',
            'target' => '<path stroke-linecap="round" stroke-linejoin="round" d="M15.042 21.672 13.684 16.6m0 0-2.51 2.225.569-9.47 5.227 7.917-3.286-.672ZM12 2.25V4.5m5.834.166-1.591 1.591M20.25 10.5H18M7.757 14.743l-1.59 1.59M6 10.5H3.75m4.007-4.243-1.59-1.59"/>',
            'colorBg' => '',
            default => '<circle cx="12" cy="12" r="9"/>',
        };
    };

    $tintForType = function (string $iconKey): string {
        return match ($iconKey) {
            'doc' => '#00d4aa',
            'globe' => 'var(--brand-icon)',
            'trend' => '#f59e0b',
            'user' => '#8b5cf6',
            'target' => '#10b981',
            default => 'var(--text-muted)',
        };
    };
@endphp

<div x-data="{ open: false }">
    <button type="button" @click="open = !open"
            class="w-full flex items-center justify-between p-3 rounded-md text-left"
            style="background: var(--surface-2); border: 1px solid var(--border);">
        <span class="flex items-center gap-2">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.6" style="color: var(--text-secondary);">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/>
            </svg>
            <span class="text-sm font-semibold" style="color: var(--text-primary);">Cross-Source Activity Timeline</span>
            <span class="text-[10px] px-1.5 py-0.5 rounded" style="background: var(--surface); color: var(--text-muted);">
                {{ $timeline->count() }} {{ \Illuminate\Support\Str::plural('event', $timeline->count()) }}
            </span>
        </span>
        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" style="color: var(--text-muted);" :class="open ? 'rotate-180' : ''">
            <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
        </svg>
    </button>

    <div x-show="open" x-cloak class="mt-2 space-y-1.5">
        @forelse($timeline as $event)
            @php $tint = $tintForType($event->icon); @endphp
            <div class="flex items-start gap-3 p-2.5 rounded-md"
                 style="background: var(--surface); border: 1px solid var(--border);">
                <div class="w-7 h-7 rounded-md flex items-center justify-center flex-shrink-0"
                     style="background: color-mix(in srgb, {{ $tint }} 12%, transparent);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" style="color: {{ $tint }};">
                        {!! $iconForType($event->icon) !!}
                    </svg>
                </div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 flex-wrap mb-0.5">
                        <span class="text-xs font-semibold" style="color: var(--text-primary);">{{ $event->label }}</span>
                        <span class="text-[10px]" style="color: var(--text-muted);">
                            {{ \Carbon\Carbon::parse($event->date)->format('j M Y') }}
                            · {{ \Carbon\Carbon::parse($event->date)->diffForHumans() }}
                        </span>
                    </div>
                    <div class="text-[11px] truncate" style="color: var(--text-secondary);">{{ $event->description }}</div>
                </div>
                @if(!empty($event->url))
                    <a href="{{ $event->url }}" target="_blank" rel="noopener"
                       class="flex-shrink-0 text-[10px] px-2 py-0.5 rounded no-underline"
                       style="background: var(--surface-2); color: var(--text-primary); border: 1px solid var(--border);">
                        Open ↗
                    </a>
                @endif
            </div>
        @empty
            <div class="p-6 text-center rounded-md text-xs italic"
                 style="background: var(--surface); border: 1px dashed var(--border); color: var(--text-muted);">
                No intelligence events recorded for this property yet.
            </div>
        @endforelse
    </div>
</div>
