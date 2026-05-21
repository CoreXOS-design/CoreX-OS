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

@include('corex.market-intelligence.partials.tabs')

@include('corex.market-intelligence.partials.this-week-hero', [
    'tiles'            => $tiles ?? collect(),
    'tilesGeneratedAt' => $tilesGeneratedAt ?? null,
    'agent'            => auth()->user(),
])

<header class="mi-header"
        style="position: sticky; top: 0; z-index: 10; background: var(--surface);">
    @include('corex.market-intelligence._top-bar')
    @include('corex.market-intelligence._stats-strip')
</header>

{{-- F.8 — one-time dismissable intro banner. localStorage-gated. --}}
@include('corex.market-intelligence._intro-banner')

<div class="mi-split" style="display: grid; grid-template-columns: 200px 1fr; align-items: start;">
    @include('corex.market-intelligence._filter-rail')

    <main class="mi-main" style="min-width: 0; overflow-x: hidden; padding: 12px 16px;">
        @include('corex.market-intelligence._listings')
    </main>
</div>

@include('corex.market-intelligence._slideover')

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
    window.__micMatchTooltipCache = window.__micMatchTooltipCache || {};
    window.micMatchTooltip = function (listingId) {
        return {
            tooltip: '',
            loading: false,
            loaded: false,
            inflight: false,
            load() {
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
