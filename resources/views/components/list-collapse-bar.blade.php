{{-- List screens — the slim bar the frozen header/tiles/filters fold into while the list
     is scrolled (spec: .ai/specs/list-collapse-on-scroll.md).

     A page opts in with markup only:
       - data-list-collapse on its full-height flex-column wrapper
       - <div data-list-collapse-top><div class="lc-top__inner">…header, tiles, filters…</div></div>
       - <x-list-collapse-bar …/> directly after it
       - data-list-collapse-scroll on the scroll region

     chips: [['label' => 'For Sale', 'url' => '…removes this filter…' | null], …] --}}
@props(['title', 'summary' => null, 'chips' => []])

<div data-list-collapse-bar class="lc-bar items-center gap-2 px-4 py-2 mt-3 rounded-md flex-shrink-0 min-w-0"
     style="background:var(--surface);border:1px solid var(--border);box-shadow:0 2px 8px rgba(0,0,0,0.06);">
    <button type="button" data-list-collapse-expand
            class="text-sm font-bold leading-tight flex-shrink-0 cursor-pointer"
            style="color:var(--text-primary);background:none;border:none;padding:0;"
            title="Show all filters">{{ $title }}</button>
    @if($summary)
    <span class="text-xs flex-shrink-0 tabular-nums" style="color:var(--text-muted);">{{ $summary }}</span>
    @endif

    @if(count($chips))
    <div class="flex items-center gap-1.5 min-w-0 overflow-x-auto lc-bar__chips" style="scrollbar-width:none;">
        @foreach($chips as $chip)
            @if(!empty($chip['url']))
            <a href="{{ $chip['url'] }}"
               class="inline-flex items-center gap-1 px-2 py-1 rounded-md text-[11px] font-medium whitespace-nowrap flex-shrink-0"
               style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);"
               title="Remove this filter">
                {{ $chip['label'] }}
                <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </a>
            @else
            <span class="inline-flex items-center px-2 py-1 rounded-md text-[11px] font-medium whitespace-nowrap flex-shrink-0"
                  style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 10%, transparent); color:var(--brand-icon,#0ea5e9); border:1px solid color-mix(in srgb, var(--brand-icon,#0ea5e9) 25%, transparent);">
                {{ $chip['label'] }}
            </span>
            @endif
        @endforeach
    </div>
    @endif

    <div class="flex-1"></div>
    <span class="hidden lg:inline text-[11px] whitespace-nowrap flex-shrink-0" style="color:var(--text-muted);">Scroll up to see everything</span>
    <button type="button" data-list-collapse-expand
            class="list-header-filter inline-flex items-center gap-1.5 cursor-pointer flex-shrink-0"
            style="border-color:var(--brand-icon,#0ea5e9);color:var(--brand-icon,#0ea5e9);">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4.5h18M6 12h12m-9 7.5h6"/>
        </svg>
        Edit filters
    </button>
</div>

@once
@push('head')
<style>
    /* Fold/unfold animates the grid row between 1fr and 0fr. The inner box clips only
       while folding or folded — expanded, dropdowns and popovers overflow as normal. */
    [data-list-collapse-top] { display: grid; grid-template-rows: 1fr; transition: grid-template-rows .22s ease; flex-shrink: 0; }
    [data-list-collapse-top] > .lc-top__inner { min-height: 0; }
    .lc-collapsed [data-list-collapse-top] { grid-template-rows: 0fr; }
    .lc-collapsed [data-list-collapse-top] > .lc-top__inner,
    .lc-animating [data-list-collapse-top] > .lc-top__inner { overflow: hidden; }
    .lc-bar { display: none; }
    .lc-collapsed .lc-bar { display: flex; }
    .lc-bar__chips::-webkit-scrollbar { display: none; }
    @media (prefers-reduced-motion: reduce) {
        [data-list-collapse-top] { transition: none; }
    }
</style>
@endpush
@push('scripts')
<script>
(function () {
    const FOLD_AT = 40;      // px scrolled before the top folds away
    const TOP_AT = 4;        // at (or within this of) the top, it always unfolds
    const REFOLD_AFTER = 80; // after "Edit filters" mid-list, fold again once scrolled this much further
    const ANIM_MS = 260;
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

    function wire(root) {
        if (root.dataset.listCollapseReady) return;
        const top = root.querySelector('[data-list-collapse-top]');
        const scroller = root.querySelector('[data-list-collapse-scroll]');
        if (!top || !scroller) return;
        root.dataset.listCollapseReady = '1';

        let collapsed = false;
        let openedAt = null; // scrollTop at which the agent unfolded it by hand
        let animTimer = null;

        function setCollapsed(next) {
            if (next === collapsed) return;
            collapsed = next;
            root.classList.add('lc-animating');
            root.classList.toggle('lc-collapsed', next);
            top.inert = next;
            clearTimeout(animTimer);
            animTimer = setTimeout(() => root.classList.remove('lc-animating'), reduceMotion.matches ? 0 : ANIM_MS);
        }

        function onScroll() {
            const y = scroller.scrollTop;
            if (y <= TOP_AT) { openedAt = null; setCollapsed(false); return; }
            if (openedAt !== null) {
                if (y > openedAt + REFOLD_AFTER) { openedAt = null; setCollapsed(true); }
                return;
            }
            if (!collapsed && y > FOLD_AT) {
                // Fold only when the list stays scrolled once the top is gone; on a short
                // list it would snap back to the top and flicker between the two states.
                const roomAfterFold = scroller.scrollHeight - scroller.clientHeight - top.offsetHeight;
                if (roomAfterFold > FOLD_AT) setCollapsed(true);
            }
        }

        scroller.addEventListener('scroll', onScroll, { passive: true });
        root.addEventListener('click', (e) => {
            if (!e.target.closest('[data-list-collapse-expand]')) return;
            openedAt = scroller.scrollTop;
            setCollapsed(false);
        });
        onScroll();
    }

    function wireAll() { document.querySelectorAll('[data-list-collapse]').forEach(wire); }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', wireAll);
    else wireAll();
})();
</script>
@endpush
@endonce
