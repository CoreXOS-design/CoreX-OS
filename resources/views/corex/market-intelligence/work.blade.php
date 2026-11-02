{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 (F.7 audit). --}}
{{--
    MIC Phases D1+D2+D3 — Work tab.

    Layout (top to bottom):
      tabs nav (Work | Opportunities | Analyse | Market Pulse)
      11-tile row + panels (MicWorkTiles)
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
    :compact="true">
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

{{-- 11-tile row + tile 10 / 11 panels (mic-work-tab.md 2.1). The old "Hi ..., here's your week" greeting block is gone. --}}
<div data-tour="mic-tiles">
@include('corex.market-intelligence.partials._work-tiles', ['workTiles' => $workTiles ?? [], 'workPanel' => $workPanel ?? [], 'tilesDeferred' => $tilesDeferred ?? false])
</div>
@if(!empty($tileNote))
<div data-qa="tile-note" style="margin: -4px 0 8px 0; font-size: .75rem; color: var(--text-secondary);">
    <strong>{{ $tileNote['title'] }}</strong>:
    @if($tileNote['files'] !== null){{ number_format($tileNote['files']) }} properties →@endif
    {{ number_format($tileNote['rows']) }} rows below
    @if($tileNote['files'] !== null && $tileNote['rows'] > $tileNote['files'])<span style="color: var(--text-muted);">(a property whose P24 and PP adverts are not linked yet shows as more than one row)</span>
    @elseif($tileNote['files'] !== null && $tileNote['rows'] < $tileNote['files'])<span style="color: var(--text-muted);">({{ number_format($tileNote['files'] - $tileNote['rows']) }} of them have no advert this list shows - taken down, or outside your scope)</span>@endif
    @if($tileNote['tileCount'] !== null)<span style="color: var(--text-muted);"> · the tile's {{ number_format($tileNote['tileCount']) }} counts "{{ $tileNote['tileCounts'] }}", not this list</span>@endif
    @if($tileNote['asOf'])<span style="color: var(--text-muted);"> · figures as of {{ $tileNote['asOf'] }}</span>@endif
    · <a href="{{ route('market-intelligence.work', request()->except(['tile', 'page'])) }}" style="color: var(--brand-icon); text-decoration: none;">Clear this tile</a>
</div>
@endif

@if(($micView ?? 'adverts') === 'properties')
<div id="mic-slot-listings" data-tour="mic-listings">
@include('corex.market-intelligence.partials._work-property-list')
</div>
@else
{{-- Top filter bar (mic-work-tab.md 13.2), directly under the tiles. It replaces the left column: the quick filters, the chips, the sort and the
     Adverts | List switch, and under "More filters" every old group, the buyer-match legend and the ticks. The swap target for a tick refresh. --}}
<div id="mic-slot-filter-rail" data-tour="mic-filters">
@include('corex.market-intelligence._filter-bar')
</div>

{{-- Sticky offset is the main area's own top padding (p-4 / lg:p-6), set in the
     <style> block below, so the strip pins flush to the top edge of the scroll
     area — at top:0 a padding-high band above it showed the list scrolling
     through (Andre, 2026-10-14). --}}
<header class="mi-header" id="mic-slot-stats"
        style="position: sticky; z-index: 10; background: var(--surface);">
    @include('corex.market-intelligence._stats-strip', ['statsLine' => true])
</header>

{{-- F.8 — one-time dismissable intro banner. localStorage-gated. --}}
@include('corex.market-intelligence._intro-banner')

<div class="mi-split" data-tour="mic-list" style="display: grid; grid-template-columns: 1fr; align-items: start;">

    <main class="mi-main" id="mic-slot-listings" data-tour="mic-listings" style="min-width: 0; overflow-x: hidden; padding: 12px 16px;">
        @include('corex.market-intelligence._listings')
    </main>
</div>

@endif
</div>{{-- /full-width container --}}

@if(($micView ?? 'adverts') !== 'properties')
@include('corex.market-intelligence._slideover')
@endif
@include('corex.market-intelligence._buyer-banner-script')

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
    .mi-header { top: -1rem; }
    /* Buyer rows in the rail: layout lives here, not inline, because Alpine's
       x-show removes the inline display property when it re-shows an element. */
    .mi-rail-row { display: flex; justify-content: space-between; align-items: center; }
    /* the old left-column groups, now laid out in a grid inside the top bar's "More filters" */
    .mi-filter-bar-more .mi-filter-rail {
        width: auto; border-right: 0; position: static; max-height: none; overflow: visible; background: transparent;
        display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 0 14px; align-items: start;
    }
    .mi-filter-bar-more .mi-filter-rail > div { border: 1px solid var(--border); border-radius: 4px; margin-bottom: 8px; max-height: 300px; overflow-y: auto; }
    .mi-filter-rail {
        width: 200px;
        flex-shrink: 0;
        background: var(--surface);
        border-right: 1px solid var(--border);
        position: sticky;
        top: calc(var(--mi-header-h, 110px) - 1rem);
        max-height: calc(100vh - var(--mi-header-h, 110px));
        overflow-y: auto;
        align-self: start;
    }
    @media (min-width: 1024px) {
        .mi-header { top: -1.5rem; }
        .mi-filter-rail { top: calc(var(--mi-header-h, 110px) - 1.5rem); }
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
{{-- mic-work-tab.md 13.12: the "Not the same property" button in the "Listed by N agencies" popover of an Adverts card. First press arms it (amber, "Confirm - not the same property", back to normal after 6 s or a click elsewhere); the second press splits that advert off; an Undo note shows for 10 s. No browser confirm(). --}}
<script>
(function () {
    function register() {
        window.Alpine.data('miSplit', function (cardId, count, ids, base, fileUrl) {
            var st = {};
            ids.forEach(function (id) { st[id] = { armed: false, busy: false, gone: false, msg: '' }; });
            var post = function (url) {
                var meta = document.querySelector('meta[name="csrf-token"]');
                return fetch(url, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': meta ? meta.content : '' } })
                    .then(function (r) { return r.json().catch(function () { return { ok: false }; }); });
            };
            return {
                n: count, st: st, note: null, noteAdvert: null, viewHref: null, timers: {}, noteTimer: null,
                // a hairline goes above an entry only when an entry above it is still showing (ids are in the popover's order, newest first)
                rule: function (id) {
                    for (var i = 0; i < ids.length; i++) { if (ids[i] === id) { return false; } if (!this.st[ids[i]].gone) { return true; } }
                    return false;
                },
                label: function (id) {
                    var s = this.st[id];
                    return s.msg !== '' ? s.msg : (s.armed ? 'Confirm - not the same property' : 'Not the same property');
                },
                disarm: function () { var self = this; Object.keys(this.st).forEach(function (id) { self.st[id].armed = false; clearTimeout(self.timers[id]); }); },
                press: function (id, ev) {
                    ev.splitBtn = id;
                    var s = this.st[id];
                    if (s.busy) { return; }
                    if (!s.armed) {
                        this.disarm(); s.msg = ''; s.armed = true;
                        this.timers[id] = setTimeout(function () { s.armed = false; }, 6000);
                        return;
                    }
                    s.armed = false; clearTimeout(this.timers[id]); s.busy = true;
                    var me = this;
                    post(base + '/' + id + '/split').then(function (d) {
                        s.busy = false;
                        if (!d || !d.ok) { s.msg = (d && d.message) || "Couldn't split - try again"; return; }
                        s.gone = true;
                        me.n = (typeof d.agencies_left_on_file === 'number') ? d.agencies_left_on_file : me.n - 1;
                        me.viewHref = d.new_file_id ? fileUrl.replace('__ID__', d.new_file_id) : null;
                        me.note = d.split_id; me.noteAdvert = id;
                        clearTimeout(me.noteTimer);
                        me.noteTimer = setTimeout(function () { me.note = null; }, 10000);
                    }).catch(function () { s.busy = false; s.msg = "Couldn't split - try again"; });
                },
                undo: function () {
                    var me = this, id = this.noteAdvert, splitId = this.note;
                    if (!splitId) { return; }
                    post(base + '/split/' + splitId + '/undo').then(function (d) {
                        if (!d || !d.ok) { me.note = null; return; }
                        me.st[id].gone = false; me.n = (typeof d.agencies_left_on_file === 'number') ? d.agencies_left_on_file : me.n + 1; me.note = null; clearTimeout(me.noteTimer);
                    }).catch(function () { me.note = null; });
                },
            };
        });
        // 13.12b: opens down when the whole panel fits below the trigger in the window, else up; if neither fits, the larger side with the content scrolling; placed again on resize and scroll
        window.Alpine.data('micPop', function (align) {
            return {
                open: false, side: 'down', align: align, h: null,
                pos: { top: '100%', bottom: 'auto', left: '0px', right: 'auto', marginTop: '8px', marginBottom: '0px', transformOrigin: 'top ' + align, '--pop-max': '22rem' },
                init: function () {
                    var self = this;
                    this.h = function (e) { if (self.open && !(e && e.target && e.target.nodeType === 1 && self.$refs.panel.contains(e.target))) { self.place(); } };
                    this.$watch('open', function (v) {
                        if (v) { self.$nextTick(function () { self.place(); }); window.addEventListener('resize', self.h); window.addEventListener('scroll', self.h, true); }
                        else { window.removeEventListener('resize', self.h); window.removeEventListener('scroll', self.h, true); }
                    });
                },
                place: function () {
                    var t = this.$refs.trigger.getBoundingClientRect(), panel = this.$refs.panel, inner = this.$refs.inner, c = inner.firstElementChild;
                    var gap = 8, edge = 8, vh = window.innerHeight, vw = window.innerWidth;
                    var chrome = inner.offsetHeight - (c ? c.offsetHeight : 0);
                    var cap = parseFloat(getComputedStyle(document.documentElement).fontSize) * 22;
                    var want = Math.min(c ? c.scrollHeight : inner.offsetHeight, cap) + chrome;
                    var below = vh - t.bottom - gap - edge, above = t.top - gap - edge;
                    var down = want <= below || (want > above && below >= above);
                    var room = down ? below : above;
                    var maxContent = want <= room ? cap : Math.max(80, room - chrome);
                    var w = panel.offsetWidth, base = this.align === 'left' ? 0 : t.width - w, abs = t.left + base, dx = 0;
                    if (abs < edge) { dx = edge - abs; } else if (abs + w > vw - edge) { dx = vw - edge - (abs + w); }
                    this.side = down ? 'down' : 'up';
                    this.pos = { top: down ? '100%' : 'auto', bottom: down ? 'auto' : '100%', left: (base + dx) + 'px', right: 'auto', marginTop: down ? gap + 'px' : '0px', marginBottom: down ? '0px' : gap + 'px', transformOrigin: (down ? 'top ' : 'bottom ') + this.align, '--pop-max': maxContent + 'px' };
                },
            };
        });
    }
    if (window.Alpine && window.Alpine.data) { register(); } else { document.addEventListener('alpine:init', register); }
})();
</script>
@endsection
