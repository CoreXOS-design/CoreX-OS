{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    MIC Phases D1+D2+D3 — Work tab.

    Layout (top to bottom):
      tabs nav (Work | Opportunities | Analyse | Market Pulse)
      "This Week" hero block (deterministic tiles per agent)
      sticky header: top-bar + simplified 5-tile stats strip
      filter rail + listing list
      slide-over detail panel

    Spec: .ai/specs/mic-complete-spec.md §5.2, §5.3, §6.
--}}
@extends('layouts.corex-app')

@section('corex-content')

{{-- Full-width container — matches Contacts / Properties / the Analyse tab so
     the branded header, tab menu, stats strip, and worklist all render at the
     same full page-padded width as every other index page (not a narrower
     inset slab). Also normalises the sticky-header height measurement the
     filter rail and stats strip depend on, so their menus position correctly. --}}
<div style="width: 100%;">

<x-mic-page-header
    title="Work"
    subtitle="Your prospecting worklist — listings to action, ranked by suggested next step.">
    <x-slot:actions>
        {{-- Slot: filter ticks. AJAX swap target (display:contents = no layout box),
             so a tick toggle refreshes this in place without a full reload. --}}
        <span id="mic-slot-header-actions" style="display:contents;">
        @include('corex.market-intelligence.partials._header-actions', ['showTicks' => false])
        </span>
    </x-slot:actions>
</x-mic-page-header>

<div data-tour="mic-tabs">
@include('corex.market-intelligence.partials.tabs')
</div>

<div data-tour="mic-hero">
@include('corex.market-intelligence.partials.this-week-hero', [
    'tiles'            => $tiles ?? collect(),
    'tilesGeneratedAt' => $tilesGeneratedAt ?? null,
    'agent'            => auth()->user(),
])
</div>

<div data-tour="mic-upload">
@include('corex.market-intelligence.partials.quick-upload-cma')
</div>

<header class="mi-header" id="mic-slot-stats"
        style="position: sticky; top: 0; z-index: 10; background: var(--surface);">
    @include('corex.market-intelligence._stats-strip')
</header>

{{-- F.8 — one-time dismissable intro banner. localStorage-gated. --}}
@include('corex.market-intelligence._intro-banner')

{{-- Buyer-tier legend + filter ticks (Johan 2026-08-19) — sits here, in the
     empty band between the banner and the list, in normal flow. NOT inside
     the scrolling list column (was overlaying the first property row when
     it lived there) and NOT sticky. AJAX swap target like the other four
     fragments below. --}}
<div id="mic-slot-buyer-legend">
@include('corex.market-intelligence._buyer-match-legend')
</div>

<div class="mi-split" data-tour="mic-list" style="display: grid; grid-template-columns: 200px 1fr; align-items: start;">
    {{-- display:contents keeps the rail as grid column 1 while giving the swap a
         stable target — a tick refresh replaces its contents in place. --}}
    <div id="mic-slot-filter-rail" style="display:contents;">
    @include('corex.market-intelligence._filter-rail')
    </div>

    <main class="mi-main" id="mic-slot-listings" style="min-width: 0; overflow-x: hidden; padding: 12px 16px;">
        @include('corex.market-intelligence._listings')
    </main>
</div>

</div>{{-- /full-width container --}}

@include('corex.market-intelligence._slideover')

{{-- ─────────────────────────────────────────────────────────────────────────
     Tick refresh (cc6) — AJAX partial swap, no full-page reload.

     The filter ticks (a.mic-tick, rendered by _header-actions /
     _work-filter-ticks) are plain <a href> toggle links. This handler
     intercepts them ON THE WORK TAB, flips the clicked glyph optimistically
     for instant feedback, then fetches the same URL with _fragments=1 and
     swaps the five fragments the controller returns (listings, stats-strip,
     filter-rail, header-actions, buyer-legend). The page never reloads;
     scroll position and the rest of the DOM are preserved.

     Event delegation on document = survives the header-actions swap.
     Facet / sort / pagination links are intentionally NOT hijacked (out of
     scope).

     TIMEOUT (Johan, AT-incident 2026-08-19): fetch() has no default timeout
     — if the server accepts the connection but never answers (a dead/hung
     backend, not a 4xx/5xx), the promise never settles and this control was
     found stuck on "P24 updating…" for hours during a real outage, with no
     error and no way to tell a hung app from a slow one. An AbortController
     now bounds every tick request to TICK_TIMEOUT_MS; on abort OR any other
     failure the tick reverts to its pre-click state and shows a plain
     "no response — tap to retry" (never blames the user, always names the
     server) that clears itself after a few seconds. Deliberately NO
     full-page-navigation fallback any more — during a real outage that just
     trades this hang for a worse, harder-to-diagnose one (the whole tab
     hanging instead of one control).
────────────────────────────────────────────────────────────────────────── --}}
<script>
(function () {
    var TICK_TIMEOUT_MS = 8000;
    var TICK_SPIN_DEFAULT_TEXT = 'updating…';
    var TICK_SPIN_FAIL_TEXT = 'no response — tap to retry';
    var TICK_SPIN_FAIL_CLEAR_MS = 4000;

    var BOX_ON  = { background: '#fff', borderColor: '#fff', color: 'var(--brand-default,#0b2a4a)' };
    var BOX_OFF = { background: 'transparent', borderColor: 'rgba(255,255,255,0.5)', color: 'transparent' };

    function setGlyph(box, on) {
        if (!box) return;
        var s = on ? BOX_ON : BOX_OFF;
        box.style.background  = s.background;
        box.style.borderColor = s.borderColor;
        box.style.color       = s.color;
        box.textContent       = on ? '✓' : '';
    }
    function swap(id, html) {
        var el = document.getElementById(id);
        if (el && typeof html === 'string') el.innerHTML = html;
    }

    // Race guard (Johan): only ONE tick refresh may be in flight. Ticking two
    // filters quickly used to lose the second — it fetched against pre-refresh
    // state and the last swap won. Now, while a refresh is in flight, every tick
    // is disabled + greyed so it can't be clicked; the successful swap re-renders
    // fresh (enabled) ticks the instant it completes, and inFlight is cleared.
    // A click that slips through anyway (e.g. a synthetic/keyboard event that
    // ignores pointer-events:none) is IGNORED, not queued or cancelled-and-
    // replaced — simplest and safest: no risk of two in-flight requests racing
    // to swap the same DOM.
    var inFlight = false;

    function lockTicks(clicked) {
        document.querySelectorAll('a.mic-tick').forEach(function (t) {
            t.style.pointerEvents = 'none';          // un-clickable
            t.setAttribute('aria-disabled', 'true');
            t.style.opacity = (t === clicked) ? '0.65' : '0.4'; // clicked keeps its cue; others greyed
        });
    }
    function unlockTicks() {
        document.querySelectorAll('a.mic-tick').forEach(function (t) {
            t.style.pointerEvents = '';
            t.removeAttribute('aria-disabled');
            t.style.opacity = '';
        });
    }

    document.addEventListener('click', function (e) {
        var a = e.target.closest ? e.target.closest('a.mic-tick') : null;
        if (!a) return;
        // Only hijack on the Work tab (the swap slots exist there). Elsewhere the
        // link navigates normally.
        if (!document.getElementById('mic-slot-listings')) return;
        e.preventDefault();

        // A refresh is already running — ignore this click (belt-and-suspenders
        // alongside the greyed pointer-events:none lock below).
        if (inFlight) return;
        inFlight = true;

        var href  = a.getAttribute('href');
        var wasOn = a.getAttribute('data-active') === '1';
        var box   = a.querySelector('.mic-tick-box');
        var spin  = a.querySelector('.mic-tick-spin');

        // Optimistic: flip this tick's glyph + show "updating…", then lock ALL
        // ticks (disable + grey the others) until the refresh completes.
        setGlyph(box, !wasOn);
        if (spin) { spin.textContent = TICK_SPIN_DEFAULT_TEXT; spin.style.display = 'inline'; }
        lockTicks(a);

        function resetAfterFailure() {
            inFlight = false;
            unlockTicks();
            // We don't know whether the server ever saw the toggle — assume it
            // didn't and put the glyph back exactly where it was before the click.
            setGlyph(box, wasOn);
            if (spin) {
                spin.textContent = TICK_SPIN_FAIL_TEXT;
                spin.style.display = 'inline';
                setTimeout(function () {
                    spin.textContent = TICK_SPIN_DEFAULT_TEXT;
                    spin.style.display = 'none';
                }, TICK_SPIN_FAIL_CLEAR_MS);
            }
        }

        var controller = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        var timedOut = false;
        var timeoutId = controller ? setTimeout(function () {
            timedOut = true;
            controller.abort();
        }, TICK_TIMEOUT_MS) : null;

        var url = href + (href.indexOf('?') === -1 ? '?' : '&') + '_fragments=1';
        fetch(url, {
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            credentials: 'same-origin',
            signal: controller ? controller.signal : undefined
        })
        .then(function (r) {
            if (timeoutId) clearTimeout(timeoutId);
            if (!r.ok) throw new Error('HTTP ' + r.status);
            return r.json();
        })
        .then(function (d) {
            swap('mic-slot-listings',       d.listings);
            swap('mic-slot-stats',          d.statsStrip);
            swap('mic-slot-filter-rail',    d.filterRail);
            swap('mic-slot-header-actions', d.headerActions);
            swap('mic-slot-buyer-legend',   d.buyerLegend);
            // Fresh header-actions come back enabled; if it wasn't returned for any
            // reason, re-enable the in-place ticks so they never stay stuck greyed.
            if (!d.headerActions) unlockTicks();
            if (d.url && window.history && window.history.pushState) {
                window.history.pushState({ micTick: true }, '', d.url);
            }
            inFlight = false;
        })
        .catch(function () {
            if (timeoutId) clearTimeout(timeoutId);
            // Deliberately no full-page-navigation fallback — see header comment.
            // timedOut just distinguishes the two only for anyone reading logs;
            // the user-facing reset is identical either way (fail loud and fast).
            resetAfterFailure();
        });
    });

    // A back/forward across a tick-swap re-renders server-side truth.
    window.addEventListener('popstate', function (e) {
        if (e.state && e.state.micTick) window.location.reload();
    });
})();
</script>

<style>
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
        requestAnimationFrame(setHeaderHeight);
    })();

    // Phase E3 — per-listing "why this matches" tooltip.
    // Cache per-listing in-memory so repeated hovers don't refetch.
    //
    // 2026-08-14: `open` now gates visibility (set by mouseenter/focusin,
    // cleared by mouseleave/focusout in the blade) — a true hover-reveal,
    // transient tooltip instead of the old one-way latch that stayed stuck
    // open forever after the first hover. The fetched text is still cached
    // per-listing so re-hovering the same row never re-fetches.
    window.__micMatchTooltipCache = window.__micMatchTooltipCache || {};
    window.micMatchTooltip = function (listingId) {
        return {
            tooltip: '',
            loading: false,
            loaded: false,
            inflight: false,
            open: false,
            show() {
                this.open = true;
                this.ensureLoaded();
            },
            hide() {
                this.open = false;
            },
            ensureLoaded() {
                if (this.loaded || this.inflight) return;
                if (window.__micMatchTooltipCache[listingId]) {
                    this.tooltip = window.__micMatchTooltipCache[listingId];
                    this.loaded = true;
                    return;
                }
                this.inflight = true;
                this.loading = true;
                fetch('/corex/market-intelligence/listing/' + listingId + '/match-tooltip', {
                    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                })
                .then(r => r.ok ? r.json() : Promise.reject('HTTP ' + r.status))
                .then(data => {
                    this.tooltip = data.tooltip || '';
                    window.__micMatchTooltipCache[listingId] = this.tooltip;
                    this.loaded = true;
                })
                .catch(() => { this.tooltip = ''; })
                .finally(() => { this.loading = false; this.inflight = false; });
            },
        };
    };
</script>
@endsection
