{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    F.3 Work mode — sticky top bar + stats strip + filter rail.

    Sticky offsets:
      <header> (top bar + stats strip) — sticky at top: 0, z-index 10
      <aside.mi-filter-rail>           — sticky at top: var(--mi-header-h)
      <main>                            — normal flow, scrolls naturally

    The CSS variable --mi-header-h is set from JS on load and on resize
    so the rail's sticky-top always matches the live header height even
    if the stats grid wraps to two rows on narrow viewports.

    Spec: build-f-market-intelligence-redesign-spec.md §8.
--}}

<header class="mi-header"
        style="position: sticky; top: 0; z-index: 10; background: var(--surface);">
    @include('corex.market-intelligence._top-bar')
    @include('corex.market-intelligence._stats-strip')
</header>

{{-- F.8 — one-time dismissable intro banner. localStorage-gated; bumping the
     version suffix in the partial re-shows it to everyone. --}}
@include('corex.market-intelligence._intro-banner')

<div class="mi-split" style="display: grid; grid-template-columns: 200px 1fr; align-items: start;">
    @include('corex.market-intelligence._filter-rail')

    <main class="mi-main" style="min-width: 0; overflow-x: hidden; padding: 12px 16px;">
        @include('corex.market-intelligence._listings')
    </main>
</div>

{{-- F.4 — single slide-over instance for the page; row clicks dispatch
     `open-slideover` events that this component handles. --}}
@include('corex.market-intelligence._slideover')

<style>
    /* Sticky rail — top offset matches header height. JS below keeps it in sync. */
    .mi-filter-rail {
        width: 200px;
        flex-shrink: 0;
        background: var(--surface);
        border-right: 1px solid var(--border);
        position: sticky;
        top: var(--mi-header-h, 110px);
        max-height: calc(100vh - var(--mi-header-h, 110px));
        overflow-y: auto;
        align-self: start;
    }
    /* Mobile fallback — collapse the rail and let stats wrap. F.G handles full mobile. */
    @media (max-width: 768px) {
        .mi-split { grid-template-columns: 1fr !important; }
        .mi-filter-rail { display: none; }
        .mi-row { grid-template-columns: 44px 1fr !important; }
        .mi-row > div:last-child {
            grid-column: 1 / -1;
            align-items: flex-start !important;
            flex-direction: row !important;
            flex-wrap: wrap;
        }
    }
    [x-cloak] { display: none !important; }
</style>

<script>
    (function () {
        var setHeaderHeight = function () {
            var h = document.querySelector('.mi-header');
            if (!h) return;
            document.documentElement.style.setProperty('--mi-header-h', h.offsetHeight + 'px');
        };
        setHeaderHeight();
        window.addEventListener('resize', setHeaderHeight);
        // Re-measure once after first paint in case fonts shift the box.
        requestAnimationFrame(setHeaderHeight);
    })();
</script>
