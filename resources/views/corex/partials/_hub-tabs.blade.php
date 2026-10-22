{{--
    .ai/specs/real-estate-menu-tabs.md - the tab bar of a tabbed menu item (App\Support\Navigation\TabHub). Shown at the top of each of the
    hub's screens; a user sees only the tabs they may open. Renders nothing on a route none of the hub's tabs matches (e.g. the Rentals
    entry of a shared screen).
    Receives: $hub (the TabHub class), optional $hubActiveTab (tab key, overrides the route match), optional $hubFlush (no space below the bar, for a
    dense screen header such as the Calendar's), optional $hubAlpineTabs (tab keys this screen switches
    itself, client-side, through its own Alpine `tab` - those tabs keep their link as a fallback and do not reload the page).
    (Option names are prefixed: an @include also sees the host page's own variables, and $active is a common one.)
    Styling copied from the Market intelligence tab bar (horizontal scroll on narrow screens so the row never overflows the page).
--}}
@php
    $hubTabs = $hub::forUser(auth()->user());
    $hubActive = $hubActiveTab ?? $hub::activeKey();
    $hubAlpine = $hubAlpineTabs ?? [];
@endphp
@if($hubActive !== null && count($hubTabs) > 0)
<nav class="corex-hub-tabs" data-hub-tabs aria-label="{{ $hub::label() }}"
     style="display: flex; align-items: center; border-bottom: 1px solid var(--border, rgba(0,0,0,0.07)); margin-bottom: {{ ($hubFlush ?? false) ? '0' : '16px' }}; padding: 0 4px; flex-shrink: 0;">
    <div style="display: flex; gap: 2px; overflow-x: auto; max-width: 100%; -webkit-overflow-scrolling: touch; scrollbar-width: thin;">
        @foreach($hubTabs as $hubKey => $hubTab)
            @php
                $hubBase = 'padding: 10px 16px; text-decoration: none; font-size: 0.875rem; font-weight: 500; white-space: nowrap; border-bottom: 2px solid transparent; color: var(--text-muted, #9ca3af); border-top-left-radius: 4px; border-top-right-radius: 4px; display: inline-flex; align-items: center; gap: 6px;';
                $hubOn = 'padding: 10px 16px; text-decoration: none; font-size: 0.875rem; font-weight: 600; white-space: nowrap; border-bottom: 2px solid var(--brand-icon, #0ea5e9); color: var(--brand-icon, #0ea5e9); background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 8%, transparent); border-top-left-radius: 4px; border-top-right-radius: 4px; display: inline-flex; align-items: center; gap: 6px;';
                $hubIsAlpine = in_array($hubKey, $hubAlpine, true);
            @endphp
            @if($hubIsAlpine)
                <a href="{{ $hubTab['url'] }}" data-hub-tab="{{ $hubKey }}" @click.prevent="tab = '{{ $hubKey }}'" @if($hubKey === $hubActive) aria-current="page" @endif
                   :aria-current="tab === '{{ $hubKey }}' ? 'page' : null"
                   :style="tab === '{{ $hubKey }}' ? '{{ $hubOn }}' : '{{ $hubBase }}'"
                   style="{{ $hubKey === $hubActive ? $hubOn : $hubBase }}">
            @else
                <a href="{{ $hubTab['url'] }}" data-hub-tab="{{ $hubKey }}" @if($hubKey === $hubActive) aria-current="page" @endif
                   style="{{ $hubKey === $hubActive ? $hubOn : $hubBase }}">
            @endif
                <span>{{ $hubTab['label'] }}</span>
                @if(($hubTab['badge'] ?? 0) > 0)
                    <span class="inline-flex items-center justify-center min-w-[18px] h-[18px] px-1.5 rounded-full text-[0.6875rem] font-bold"
                          style="background: color-mix(in srgb, {{ $hub::badgeColor() }} 15%, transparent); color: {{ $hub::badgeColor() }};">{{ $hubTab['badge'] > 99 ? '99+' : $hubTab['badge'] }}</span>
                @endif
            </a>
        @endforeach
    </div>
</nav>
@endif
