{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}
{{--
    AT-164 Gate 3 — Unified Tile Library.

    ONE tile shell, TWO surfaces (Command Centre dashboard + Calendar Deck). Consumes the
    CommandCentreService card array shape:
      { card_id, title, icon, urgency, count, items[], view_all_url, rag?, degraded? }

    Contract deltas over the embryonic dashboard tile (audit 2026-07-03):
      1. Independent scroll body   — body scrolls (max-height), never capped-and-hidden.
      2. RAG-accent variant        — card.rag (green|amber|red|overdue|neutral) drives the accent;
                                     falls back to urgency when rag is absent.
      3. Per-row new-tab click     — every item with a url opens in a new tab (target=_blank).
      4. Collapse                  — header chevron collapses the body (local per-tile state).
    Plus: empty state and degraded state (data-source error → quiet "couldn't load", never 500).

    Alpine-bound. Designed to live INSIDE a parent `x-for="card in ..."`; the card variable name
    is configurable via the `var` prop (default `card`). All render helpers are delegated to the
    shared global `window.CoreXTile` (emitted once below) so both surfaces stay pixel-identical.

    Props:
      var  — Alpine expression pointing at the card object in the enclosing scope (default 'card').
--}}
@props(['var' => 'card', 'compact' => false])

@once
@push('scripts')
<script>
/* AT-164 Gate 3 — shared Tile render helpers. Defined once; consumed by the dashboard
   (commandCentre) and the Calendar Deck. Token-aware colours resolve at call time so a
   theme swap is honoured without a rebuild. */
window.CoreXTile = (function () {
    const tok = (name, fallback) => {
        const v = getComputedStyle(document.documentElement).getPropertyValue(name).trim();
        return v || fallback;
    };
    const urgencyColour = (u) => ({
        critical: tok('--ds-crimson', '#c41e3a'),
        high:     tok('--ds-amber',   '#f59e0b'),
        medium:   tok('--brand-icon', '#0ea5e9'),
        low:      tok('--text-muted', '#9ca3af'),
    }[u] || tok('--text-muted', '#9ca3af'));
    const ragColour = (rag) => ({
        red:      tok('--ds-crimson', '#c41e3a'),
        overdue:  tok('--ds-crimson', '#c41e3a'),
        amber:    tok('--ds-amber',   '#f59e0b'),
        green:    tok('--ds-green',   '#059669'),
        neutral:  tok('--text-muted', '#9ca3af'),
    }[rag] || tok('--text-muted', '#9ca3af'));
    const ICONS = {
        'calendar': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3 18.75V7.5a2.25 2.25 0 0 1 2.25-2.25h13.5A2.25 2.25 0 0 1 21 7.5v11.25m-18 0A2.25 2.25 0 0 0 5.25 21h13.5A2.25 2.25 0 0 0 21 18.75m-18 0v-7.5A2.25 2.25 0 0 1 5.25 9h13.5A2.25 2.25 0 0 1 21 11.25v7.5"/></svg>',
        'mail': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 0 1-2.25 2.25h-15a2.25 2.25 0 0 1-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0 0 19.5 4.5h-15a2.25 2.25 0 0 0-2.25 2.25m19.5 0v.243a2.25 2.25 0 0 1-1.07 1.916l-7.5 4.615a2.25 2.25 0 0 1-2.36 0L3.32 8.91a2.25 2.25 0 0 1-1.07-1.916V6.75"/></svg>',
        'alert-triangle': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/></svg>',
        'users': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 0 0 2.625.372 9.337 9.337 0 0 0 4.121-.952 4.125 4.125 0 0 0-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 0 1 8.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0 1 11.964-3.07M12 6.375a3.375 3.375 0 1 1-6.75 0 3.375 3.375 0 0 1 6.75 0Zm8.25 2.25a2.625 2.625 0 1 1-5.25 0 2.625 2.625 0 0 1 5.25 0Z"/></svg>',
        'activity': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
        'home': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 12 8.954-8.955c.44-.439 1.152-.439 1.591 0L21.75 12M4.5 9.75v10.125c0 .621.504 1.125 1.125 1.125H9.75v-4.875c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21h4.125c.621 0 1.125-.504 1.125-1.125V9.75M8.25 21h8.25"/></svg>',
        'shield': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>',
        'shield-check': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z"/></svg>',
        'clock': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
        'eye': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 0 1 0-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178Z"/><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0Z"/></svg>',
        'building': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21"/></svg>',
        'clipboard-check': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M11.35 3.836c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 0 0 .75-.75 2.25 2.25 0 0 0-.1-.664m-5.8 0A2.251 2.251 0 0 1 13.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m8.9-4.414c.376.023.75.05 1.124.08 1.131.094 1.976 1.057 1.976 2.192V16.5A2.25 2.25 0 0 1 18 18.75h-2.25m-7.5-10.5H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V18.75m-7.5-10.5h6.375c.621 0 1.125.504 1.125 1.125v9.375m-8.25-3 1.5 1.5 3-3.75"/></svg>',
        'trending-down': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6 9 12.75l4.286-4.286a11.948 11.948 0 0 1 4.306 6.43l.776 2.898M18.75 19.5l3-3m0 0-3-3m3 3H15"/></svg>',
        'bar-chart': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z"/></svg>',
        'alert-circle': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>',
        'lightbulb': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 0 0 1.5-.189m-1.5.189a6.01 6.01 0 0 1-1.5-.189m3.75 7.478a12.06 12.06 0 0 1-4.5 0m3.75 2.383a14.406 14.406 0 0 1-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 1 0-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"/></svg>',
        'file-signature': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 0 0-3.375-3.375h-1.5A1.125 1.125 0 0 1 13.5 7.125v-1.5a3.375 3.375 0 0 0-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 0 0-9-9Z"/></svg>',
        'bell': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/></svg>',
        'check-square': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Z"/></svg>',
        'briefcase': '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 14.15v4.25c0 1.094-.787 2.036-1.872 2.18-2.087.277-4.216.42-6.378.42s-4.291-.143-6.378-.42c-1.085-.144-1.872-1.086-1.872-2.18v-4.25m16.5 0a2.18 2.18 0 0 0 .75-1.661V8.706c0-1.081-.768-2.015-1.837-2.175a48.114 48.114 0 0 0-3.413-.387m4.5 8.006c-.194.165-.42.295-.673.38A23.978 23.978 0 0 1 12 15.75c-2.648 0-5.195-.429-7.577-1.22a2.016 2.016 0 0 1-.673-.38m0 0A2.18 2.18 0 0 1 3 12.489V8.706c0-1.081.768-2.015 1.837-2.175a48.111 48.111 0 0 1 3.413-.387m7.5 0V5.25A2.25 2.25 0 0 0 13.5 3h-3a2.25 2.25 0 0 0-2.25 2.25v.894m7.5 0a48.667 48.667 0 0 0-7.5 0"/></svg>',
    };
    return {
        tok, urgencyColour, ragColour,
        icon: (name) => ICONS[name] || ICONS['clock'],
        urgencyLabel: (u) => ({ critical: 'Now', high: 'Now', medium: 'Today', low: 'FYI' }[u] || 'FYI'),
        /* RAG-accent variant (delta 2): card.rag wins; else urgency. */
        accent: (card) => (card && card.rag) ? ragColour(card.rag) : urgencyColour(card ? card.urgency : 'low'),
        /* Plain-English line for a generic item row. Mirrors the dashboard's tooltipItemText. */
        itemText: (card, item) => {
            if (!item) return '';
            if (item.title)   return item.title + (item.status ? ' — ' + item.status : (item.due ? ' — ' + item.due : ''));
            if (item.name)    return item.name + (item.reason ? ' — ' + item.reason : item.issue ? ' — ' + item.issue : '');
            if (item.contact) return item.contact + (item.status ? ' — ' + item.status : '');
            if (item.message) return item.message;
            if (item.label)   return item.label + (item.value !== undefined ? ': ' + item.value : '');
            if (item.text)    return String(item.text).slice(0, 80);
            if (item.dates)   return item.dates + (item.name ? ' — ' + item.name : '');
            return '';
        },
    };
})();
</script>
@endpush
@endonce

{{-- Tile shell. Root is a <div> (NOT a wrapping <a>) so per-row new-tab links (delta 3) are
     valid HTML. Whole-tile navigation lives in the footer "View all" link. Local `collapsed`
     state (delta 4) merges over the parent scope, so `{{ $var }}` still resolves. --}}
<div x-data="{ collapsed: false }"
     class="group flex flex-col rounded-md overflow-hidden transition-all shadow-sm hover:shadow h-full"
     :style="'background:var(--surface-2);border:1px solid var(--border);border-top:3px solid ' + window.CoreXTile.accent({{ $var }}) + ';'">

    {{-- ─── Compact header (cockpit tile strip): ONE thin line so the CONTENT LIST
         gets the height. Small icon + name + count badge + View-all. ─── --}}
    @if($compact)
    <div class="px-2.5 pt-1.5 pb-1 flex items-center gap-1.5 flex-shrink-0">
        <span x-html="window.CoreXTile.icon({{ $var }}.icon)" class="w-3.5 h-3.5 flex-shrink-0" :style="'color:' + window.CoreXTile.accent({{ $var }})"></span>
        <h3 class="text-[11px] font-semibold truncate flex-1 min-w-0" style="color: var(--text-primary);" x-text="{{ $var }}.title"></h3>
        <span x-show="({{ $var }}.count || 0) > 0" class="text-[10px] font-bold tabular-nums px-1.5 rounded-full flex-shrink-0 leading-tight"
              :style="'color:#fff; background:' + window.CoreXTile.accent({{ $var }})" x-text="({{ $var }}.count > 99) ? '99+' : {{ $var }}.count"></span>
        <template x-if="{{ $var }}.view_all_url">
            <a :href="{{ $var }}.view_all_url" target="_blank" rel="noopener" class="text-[10px] flex-shrink-0 no-underline font-semibold" style="color: var(--brand-button);" title="View all">View all →</a>
        </template>
    </div>
    @else
    {{-- ─── Header (full — dashboard) ─── --}}
    <div class="p-5 pb-3 flex items-center justify-between gap-3 flex-shrink-0">
        <div class="flex items-center gap-3 min-w-0">
            <div class="w-10 h-10 rounded-md flex items-center justify-center flex-shrink-0"
                 :style="'background: color-mix(in srgb, ' + window.CoreXTile.accent({{ $var }}) + ' 15%, transparent); color: ' + window.CoreXTile.accent({{ $var }})">
                <span x-html="window.CoreXTile.icon({{ $var }}.icon)" class="w-[1.125rem] h-[1.125rem]"></span>
            </div>
            <div class="min-w-0">
                <h3 class="text-sm font-semibold truncate leading-tight" style="color: var(--text-primary);" x-text="{{ $var }}.title"></h3>
                <span class="text-[0.6875rem] uppercase tracking-wider font-semibold"
                      :style="'color: ' + window.CoreXTile.accent({{ $var }})"
                      x-text="{{ $var }}.rag ? {{ $var }}.rag : window.CoreXTile.urgencyLabel({{ $var }}.urgency)"></span>
            </div>
        </div>
        <div class="flex items-center gap-2 flex-shrink-0">
            <span x-show="({{ $var }}.count || 0) > 0" class="text-2xl font-bold tabular-nums leading-none"
                  :style="'color:' + window.CoreXTile.accent({{ $var }})" x-text="({{ $var }}.count > 99) ? '99+' : {{ $var }}.count"></span>
            {{-- Collapse toggle (delta 4) --}}
            <button type="button" @click="collapsed = !collapsed"
                    class="w-6 h-6 rounded flex items-center justify-center transition hover:opacity-70"
                    style="color: var(--text-muted);"
                    :aria-label="collapsed ? 'Expand' : 'Collapse'">
                <svg class="w-4 h-4 transition-transform" :class="collapsed && '-rotate-90'" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5" />
                </svg>
            </button>
        </div>
    </div>
    @endif

    {{-- ─── Body (independent scroll — delta 1). In compact mode the padding is tight
         and the body fills whatever height remains so the entry lines show. ─── --}}
    <div x-show="!collapsed" x-collapse class="flex flex-col flex-1 min-h-0 {{ $compact ? 'px-2.5 pb-1.5' : 'px-5 pb-4' }}">
        {{-- Degraded state — a tile whose data source errored. Never a 500. --}}
        <template x-if="{{ $var }}.degraded">
            <div class="flex-1 flex flex-col items-center justify-center text-center py-6 gap-1">
                <svg class="w-5 h-5" style="color: var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 3.75h.008v.008H12v-.008Z"/></svg>
                <span class="text-xs" style="color: var(--text-muted);">Couldn't load right now</span>
            </div>
        </template>

        {{-- Empty state --}}
        <template x-if="!{{ $var }}.degraded && (!{{ $var }}.items || {{ $var }}.items.length === 0)">
            <div class="flex-1 flex flex-col items-center justify-center text-center py-6 gap-1">
                <svg class="w-5 h-5" style="color: var(--ds-green, #059669);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                <span class="text-xs" style="color: var(--text-muted);" x-text="{{ $var }}.empty_text || 'All clear'"></span>
            </div>
        </template>

        {{-- Populated body — the scroll area holds ALL rows (delta 1), capped only by height. --}}
        <template x-if="!{{ $var }}.degraded && {{ $var }}.items && {{ $var }}.items.length > 0">
            <div class="flex-1 min-h-0 overflow-y-auto corex-tile-scroll -mr-2 pr-2" style="max-height: 260px;">

                {{-- Inline invitation actions (bespoke body — dashboard only) --}}
                <template x-if="{{ $var }}.card_id === 'pending_invitations'">
                    <div class="space-y-2 text-xs">
                        <template x-for="item in {{ $var }}.items" :key="item.id">
                            <div>
                                <div class="truncate font-medium" style="color:var(--text-primary);" x-text="item.title"></div>
                                <div class="flex items-center gap-1 mt-1">
                                    <button type="button" @click.prevent="respondInvitation(item, 'accepted')" class="text-[0.6875rem] px-2 py-0.5 rounded-md text-white font-semibold" style="background: var(--ds-green, #059669);">Accept</button>
                                    <button type="button" @click.prevent="respondInvitation(item, 'tentative')" class="text-[0.6875rem] px-2 py-0.5 rounded-md font-semibold" style="color: var(--ds-amber, #f59e0b); background: var(--surface); border: 1px solid var(--border);">Tentative</button>
                                    <button type="button" @click.prevent="respondInvitation(item, 'declined')" class="text-[0.6875rem] px-2 py-0.5 rounded-md font-semibold" style="color: var(--ds-crimson, #c41e3a); background: var(--surface); border: 1px solid var(--border);">Decline</button>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Breakdown list (label + value rows with a RAG dot) --}}
                <template x-if="['active_buyer_pipeline','esign_activity','prospecting_activity','listings_pending_marketing'].includes({{ $var }}.card_id)">
                    <div class="space-y-2.5 text-xs">
                        <template x-for="item in {{ $var }}.items" :key="item.label">
                            <div class="flex items-center justify-between gap-2 min-w-0">
                                <div class="flex items-center gap-2 min-w-0 flex-1">
                                    <span class="w-2 h-2 rounded-full flex-shrink-0" :style="'background:' + (item.colour || 'var(--text-muted)')"></span>
                                    <span class="truncate" style="color:var(--text-secondary);" x-text="item.label"></span>
                                </div>
                                <span class="font-bold tabular-nums flex-shrink-0" style="color:var(--text-primary);" x-text="item.value"></span>
                            </div>
                        </template>
                    </div>
                </template>

                {{-- Agency snapshot 2×2 --}}
                <template x-if="{{ $var }}.card_id === 'agency_health'">
                    <div class="grid grid-cols-2 gap-x-3 gap-y-2 text-xs">
                        <template x-for="item in {{ $var }}.items" :key="'ah'"><div class="contents">
                            <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.agents"></span> <span style="color:var(--text-muted);">agents</span></div>
                            <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.listings"></span> <span style="color:var(--text-muted);">listings</span></div>
                            <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.active_buyers"></span> <span style="color:var(--text-muted);">buyers</span></div>
                            <div><span class="font-bold" style="color:var(--text-primary);" x-text="item.lost_value_30d"></span> <span style="color:var(--text-muted);">lost</span></div>
                        </div></template>
                    </div>
                </template>

                {{-- Generic list — per-row new-tab click-through (delta 3) when the row carries a url --}}
                <template x-if="!['pending_invitations','active_buyer_pipeline','esign_activity','prospecting_activity','listings_pending_marketing','agency_health'].includes({{ $var }}.card_id)">
                    <div class="space-y-1 text-xs">
                        <template x-for="(item, idx) in {{ $var }}.items" :key="item.id ?? idx">
                            <a x-show="item.url" :href="item.url" target="_blank" rel="noopener"
                               class="flex items-center gap-2 py-1 px-1.5 -mx-1.5 rounded no-underline transition-colors hover:bg-[color:var(--surface)]">
                                <span x-show="item.rag" class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="'background:' + window.CoreXTile.ragColour(item.rag)"></span>
                                <span class="truncate flex-1 leading-relaxed" style="color:var(--text-secondary);" x-text="window.CoreXTile.itemText({{ $var }}, item)"></span>
                                <svg class="w-3 h-3 flex-shrink-0 opacity-0 group-hover:opacity-50 transition-opacity" style="color:var(--text-muted);" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                            </a>
                        </template>
                        {{-- rows without a url render as plain text (no dead link) --}}
                        <template x-for="(item, idx) in {{ $var }}.items" :key="'p'+(item.id ?? idx)">
                            <div x-show="!item.url" class="flex items-center gap-2 py-1 leading-relaxed">
                                <span x-show="item.rag" class="w-1.5 h-1.5 rounded-full flex-shrink-0" :style="'background:' + window.CoreXTile.ragColour(item.rag)"></span>
                                <span class="truncate flex-1" style="color:var(--text-secondary);" x-text="window.CoreXTile.itemText({{ $var }}, item)"></span>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        {{-- Footer — whole-tile "View all" link (full mode only; compact puts it in the header) --}}
        @unless($compact)
        <template x-if="{{ $var }}.view_all_url">
            <a :href="{{ $var }}.view_all_url" target="_blank" rel="noopener"
               class="mt-auto pt-3 text-xs font-semibold flex items-center gap-1 no-underline"
               style="color:var(--brand-button);border-top:1px solid var(--border);">
                <span>View all</span><span class="group-hover:translate-x-0.5 transition-transform">&rarr;</span>
            </a>
        </template>
        @endunless
    </div>
</div>
