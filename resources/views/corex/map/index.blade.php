{{-- Phase 3g V1 — CoreX Map module (Leaflet + OSM/Esri).
     Spec: .ai/specs/presentations.md (Phase 3g build prompt). --}}
@extends('layouts.corex-app')

@push('head')
{{-- Leaflet 1.9.4 + MarkerCluster 1.5.3, free-tier OSM + Esri tiles.
     Must load in <head> BEFORE the body inline init script — otherwise
     the init runs while `L` is still undefined. --}}
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
      integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css" />
<link rel="stylesheet" href="https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
        integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
<script src="https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js"></script>
{{-- Phase 3g V2 — heatmap overlay. --}}
<script src="https://unpkg.com/leaflet.heat@0.2.0/dist/leaflet-heat.js"></script>
@endpush

@section('corex-content')
<div id="corex-map-root" style="position: relative; height: calc(100vh - 64px); margin: -16px -20px -16px; display: flex; flex-direction: column; overflow: hidden; min-height: 0;">

    {{-- ── Header bar ────────────────────────────────────────────────────── --}}
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--surface); border-bottom: 1px solid var(--border); flex-shrink: 0; z-index: 500;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <h1 style="font-size: 1.05rem; font-weight: 600; color: var(--text-primary); margin: 0;">CoreX Map</h1>
            <div id="map-loading-pill" style="display: none; padding: 4px 10px; font-size: 0.6875rem; font-weight: 500; background: var(--surface-2); color: var(--text-secondary); border-radius: 999px;">Loading pins…</div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            {{-- Base-layer toggle --}}
            <div id="base-layer-toggle" style="display: inline-flex; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 2px;">
                <button data-base="streets" class="base-pill active" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: var(--brand-button); color: #fff; border: 0; border-radius: 4px; cursor: pointer;">Streets</button>
                <button data-base="satellite" class="base-pill" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">Satellite</button>
            </div>
            {{-- View-mode toggle --}}
            <div id="view-mode-toggle" style="display: inline-flex; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 2px;">
                <button data-mode="agent" class="mode-pill active" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: var(--brand-button); color: #fff; border: 0; border-radius: 4px; cursor: pointer;">Agent View</button>
                <button data-mode="seller" class="mode-pill" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">Seller View</button>
            </div>
            <button id="reset-bounds-btn" style="padding: 6px 10px; font-size: 0.75rem; font-weight: 500; color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; cursor: pointer;">Reset to HFC area</button>
        </div>
    </div>

    {{-- ── Seller View banner ────────────────────────────────────────────── --}}
    <div id="seller-banner" style="display: none; padding: 6px 16px; background: color-mix(in srgb, var(--ds-amber, #d97706) 12%, transparent); color: var(--ds-amber, #d97706); border-bottom: 1px solid var(--ds-amber, #d97706); font-size: 0.75rem; text-align: center;">
        Seller view active — owner/contact info hidden
    </div>

    {{-- ── Body ──────────────────────────────────────────────────────────── --}}
    <div style="display: flex; flex: 1; overflow: hidden;">

        {{-- Left rail --}}
        <aside id="map-left-rail" style="width: 240px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); padding: 12px; overflow-y: auto;">
            <div style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; margin-bottom: 8px;">Layers</div>
            <div id="layer-list">
                @php
                    $layerDefs = [
                        ['key' => 'hfc_listings',    'label' => 'HFC Listings',    'colour' => '#00d4aa', 'letter' => 'H'],
                        ['key' => 'sold_comps',      'label' => 'Sold Comps',      'colour' => '#3b82f6', 'letter' => 'S'],
                        ['key' => 'active_listings', 'label' => 'Active Listings', 'colour' => '#f59e0b', 'letter' => 'A'],
                        ['key' => 'mic_subjects',    'label' => 'MIC Subjects',    'colour' => '#64748b', 'letter' => 'M'],
                        ['key' => 'scheme_owners',   'label' => 'Sectional Schemes', 'colour' => '#8b5cf6', 'letter' => 'O', 'sensitive' => true],
                    ];
                @endphp
                @foreach($layerDefs as $l)
                <label data-layer="{{ $l['key'] }}"
                       @if($l['sensitive'] ?? false) data-sensitive="1" @endif
                       style="display: flex; align-items: center; gap: 8px; padding: 6px 6px; border-radius: 4px; cursor: pointer; font-size: 0.8125rem;">
                    <input type="checkbox" checked data-layer-cb="{{ $l['key'] }}" style="margin: 0;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; background: {{ $l['colour'] }}; color: #fff; border-radius: 50%; font-size: 0.6875rem; font-weight: 700;">{{ $l['letter'] }}</span>
                    <span style="flex: 1; color: var(--text-primary);">{{ $l['label'] }}</span>
                    <span data-layer-count="{{ $l['key'] }}" style="font-size: 0.6875rem; color: var(--text-muted); font-variant-numeric: tabular-nums;">—</span>
                </label>
                @endforeach
            </div>
            <div id="layer-cap-notice" style="display: none; margin-top: 10px; padding: 8px; font-size: 0.6875rem; color: var(--ds-amber, #d97706); background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent); border-radius: 4px;"></div>

            {{-- Phase 3g V2 Part A — display mode radio. --}}
            <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border);">
                <div style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; margin-bottom: 8px;">Display mode</div>
                <div id="display-mode-group" style="display: flex; flex-direction: column; gap: 4px;">
                    @foreach(['pins' => 'Pins', 'heatmap' => 'Heatmap', 'both' => 'Both'] as $key => $label)
                    <label style="display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer; font-size: 0.8125rem;">
                        <input type="radio" name="display-mode" value="{{ $key }}" {{ $key === 'pins' ? 'checked' : '' }} style="margin: 0;">
                        <span style="color: var(--text-primary);">{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Phase 3g V2 Part B — filters section, collapsible. --}}
            <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border);">
                <button id="filters-toggle" type="button"
                        style="display: flex; align-items: center; gap: 6px; width: 100%; padding: 4px 0; background: none; border: 0; cursor: pointer; text-align: left; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600;">
                    <span id="filters-caret" style="display: inline-block; transition: transform 150ms;">▸</span>
                    <span style="flex: 1;">Filters</span>
                    <span id="filters-count" style="display: none; font-size: 0.625rem; padding: 1px 6px; background: var(--brand-button); color: #fff; border-radius: 999px; text-transform: none; letter-spacing: 0;">0</span>
                </button>
                <div id="filters-body" style="display: none; margin-top: 10px;">
                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 0.6875rem; color: var(--text-secondary); margin-bottom: 4px;">Date range</div>
                        <div style="display: flex; gap: 6px; align-items: center; font-size: 0.75rem;">
                            <input type="number" id="filter-year-from" min="2018" max="2030" value="{{ now()->year - 5 }}" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                            <span style="color: var(--text-muted);">to</span>
                            <input type="number" id="filter-year-to" min="2018" max="2030" value="{{ now()->year }}" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 0.6875rem; color: var(--text-secondary); margin-bottom: 4px;">Property type</div>
                        <div style="display: flex; flex-direction: column; gap: 3px; font-size: 0.75rem;">
                            @foreach(['house' => 'House', 'sectional' => 'Apartment / Sectional', 'townhouse' => 'Townhouse', 'vacant' => 'Vacant Land'] as $val => $label)
                            <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                                <input type="checkbox" data-filter-type="{{ $val }}" checked style="margin: 0;">
                                <span style="color: var(--text-primary);">{{ $label }}</span>
                            </label>
                            @endforeach
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 0.6875rem; color: var(--text-secondary); margin-bottom: 4px;">Price band (R)</div>
                        <div style="display: flex; gap: 6px; align-items: center; font-size: 0.75rem;">
                            <input type="number" id="filter-price-min" min="0" max="10000000" step="100000" value="0" style="width: 88px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                            <span style="color: var(--text-muted);">–</span>
                            <input type="number" id="filter-price-max" min="0" max="10000000" step="100000" value="10000000" style="width: 88px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        </div>
                    </div>

                    <div style="margin-bottom: 12px;">
                        <div style="font-size: 0.6875rem; color: var(--text-secondary); margin-bottom: 4px;">Bedrooms</div>
                        <div id="filter-bedrooms" style="display: flex; gap: 4px;">
                            @foreach([1, 2, 3, 4, 5] as $b)
                            <button type="button" data-bed="{{ $b }}" class="bed-pill" data-on="1"
                                style="padding: 4px 8px; font-size: 0.75rem; font-weight: 500; background: var(--brand-button); color: #fff; border: 0; border-radius: 4px; cursor: pointer;">{{ $b === 5 ? '5+' : $b }}</button>
                            @endforeach
                        </div>
                    </div>

                    <button type="button" id="filter-clear"
                        style="width: 100%; padding: 6px 10px; font-size: 0.75rem; font-weight: 500; color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; cursor: pointer;">
                        Clear filters
                    </button>
                </div>
            </div>

            {{-- Phase 3h Step 10 — demo data toggle. Default ON locally + on
                 staging; default OFF in production. The data attribute below
                 lets the JS read the env-aware default. --}}
            <div style="margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--border);">
                <label style="display: flex; align-items: center; gap: 8px; padding: 4px 0; cursor: pointer; font-size: 0.8125rem;">
                    <input type="checkbox" id="demo-toggle"
                           data-default-on="{{ app()->environment('production') ? '0' : '1' }}"
                           style="margin: 0;">
                    <span style="flex: 1; color: var(--text-primary);">Show demo data</span>
                </label>
                <div style="font-size: 0.6875rem; color: var(--text-muted); margin-top: 2px;">Synthetic pins for testing. Toggle off to see real data only.</div>
            </div>

            <div style="margin-top: 16px; padding-top: 16px; border-top: 1px solid var(--border); font-size: 0.6875rem; color: var(--text-muted);">
                <div style="font-weight: 600; margin-bottom: 4px;">Tips</div>
                <ul style="padding-left: 16px; margin: 0; line-height: 1.5;">
                    <li>Pan / zoom to refresh pins</li>
                    <li>Click a pin for details</li>
                    <li>Seller View hides owner data</li>
                </ul>
            </div>
        </aside>

        {{-- Main canvas --}}
        <main style="flex: 1; position: relative; background: #e5e7eb;">
            <div id="corex-map" style="position: absolute; inset: 0;"></div>
            <div id="empty-state" style="display: none; position: absolute; inset: 50% 0 0 0; transform: translateY(-50%); text-align: center; color: var(--text-muted); font-size: 0.875rem; pointer-events: none;">
                No data in this area yet — try importing CMA reports for this suburb.
            </div>

            {{-- Phase 3g V2 Part A5 — heatmap legend. Inverted gradient:
                 red = sparse (ops should target this area), green = dense. --}}
            <div id="heat-legend" style="display: none; position: absolute; bottom: 16px; right: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: 6px; padding: 8px 10px; font-size: 0.6875rem; color: var(--text-secondary); box-shadow: 0 2px 6px rgba(0,0,0,.12); z-index: 1000;">
                <div style="font-weight: 600; color: var(--text-primary); margin-bottom: 4px;">Coverage density</div>
                <div style="display: flex; align-items: center; gap: 6px;">
                    <div style="width: 80px; height: 8px; border-radius: 4px; background: linear-gradient(to right, #dc2626, #f59e0b, #84cc16, #16a34a);"></div>
                </div>
                <div style="display: flex; justify-content: space-between; gap: 6px; margin-top: 3px; font-size: 0.625rem;">
                    <span>Sparse</span><span>Limited</span><span>Covered</span><span>Dense</span>
                </div>
            </div>
        </main>

        {{-- Right detail panel — Phase A.1 composite-aware state machine.
             States: 'composite_list' (shows records[] of a composite location),
                     'single_detail'  (shows a single record card, optionally with
                                       a back arrow when entered from a composite). --}}
        <aside id="map-detail-panel"
               style="width: 360px; flex-shrink: 0; background: var(--surface); border-left: 1px solid var(--border); transform: translateX(100%); transition: transform 220ms ease; overflow-y: auto; position: relative;">

            {{-- Back arrow strip — visible only when single_detail entered from composite_list. --}}
            <button id="detail-back-btn" type="button"
                    style="display: none; width: 100%; align-items: center; gap: 8px; padding: 10px 16px; background: var(--surface-2); border: 0; border-bottom: 1px solid var(--border); cursor: pointer; font-size: 0.75rem; color: var(--brand-button); font-weight: 500; text-align: left;">
                <span style="font-size: 0.875rem; line-height: 1;">←</span>
                <span id="detail-back-label">Back</span>
            </button>

            {{-- Sticky header — title, subtitle, close. --}}
            <div style="display: flex; align-items: flex-start; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: var(--surface); z-index: 1;">
                <div style="flex: 1; min-width: 0;">
                    <div id="detail-title" style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="detail-subtitle" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"></div>
                </div>
                <button id="detail-close-btn" aria-label="Close panel" style="margin-left: 8px; padding: 4px; background: transparent; border: 0; color: var(--text-muted); cursor: pointer; font-size: 1rem; line-height: 1;">×</button>
            </div>

            {{-- Body — composite list OR single detail, never both visible at once. --}}
            <div style="padding: 14px 16px;">
                {{-- Composite list view: rendered when state='composite_list'. --}}
                <div id="detail-composite-list" style="display: none;">
                    <div id="composite-records"></div>
                </div>

                {{-- Single detail view: rendered when state='single_detail'. --}}
                <div id="detail-single" style="display: none;">
                    <div id="detail-address" style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 12px; display: none;"></div>
                    <div id="detail-facts"></div>
                    <div id="detail-sensitive" style="display: none; margin-top: 14px; padding: 10px; background: color-mix(in srgb, var(--ds-purple, #8b5cf6) 8%, transparent); border-left: 3px solid var(--ds-purple, #8b5cf6); border-radius: 4px;">
                        <div style="font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; color: var(--ds-purple, #8b5cf6); margin-bottom: 6px;">Agent only</div>
                        <div id="detail-sensitive-facts"></div>
                    </div>
                    <div id="detail-relationships" style="margin-top: 14px;"></div>
                    {{-- Phase A.2 — primary/secondary CTA(s), rendered per category by renderCtas(). --}}
                    <div id="detail-ctas" style="margin-top: 12px; display: flex; flex-direction: column; gap: 8px;"></div>
                </div>
            </div>
        </aside>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    // ── Leaflet load guard ────────────────────────────────────────────────
    if (typeof L === 'undefined') {
        console.error('CoreX Map: Leaflet failed to load. Check network / ad-blocker.');
        var mapEl = document.getElementById('corex-map');
        if (mapEl) {
            mapEl.innerHTML = '<div style="padding:24px;color:#64748b;font-size:0.875rem;line-height:1.5;">'
                + '<strong>Map library failed to load.</strong><br>'
                + 'Disable ad-blockers for this host and retry, or check network connectivity.'
                + '</div>';
        }
        return;
    }

    // ── Config / constants ────────────────────────────────────────────────
    const HFC_BOUNDS = { south: -31.0, north: -30.4, west: 30.0, east: 30.9 };
    const PINS_URL = @json(route('corex.map.pins'));
    const CACHE_MAX = 5;

    // Phase A.2 — endpoints + CSRF for activity logging + launches.
    const MAP_ACTIVITY_URL = @json(route('corex.map.activity.log'));
    const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]')?.content || '';
    const PROPERTY_SHOW_URL_TPL  = @json(route('corex.properties.show', ['property' => '__ID__']));
    const PROPERTY_OUTREACH_TPL  = @json(route('seller-outreach.entry.from-property', ['property' => '__ID__']));
    const MIC_REPORT_SHOW_TPL    = @json(route('market-intelligence.reports.show', ['report' => '__ID__']));
    const MIC_OPPORTUNITIES_URL  = @json(route('market-intelligence.opportunities'));

    // Single-record category visuals — composite pins use the neutral scheme below.
    const LAYER_COLOURS = {
        hfc_listings:    '#00d4aa',
        sold_comps:      '#3b82f6',
        active_listings: '#f59e0b',
        mic_subjects:    '#64748b',
        scheme_owners:   '#8b5cf6',
    };
    const LAYER_LETTERS = {
        hfc_listings: 'H', sold_comps: 'S', active_listings: 'A',
        mic_subjects: 'M', scheme_owners: 'O',
    };
    const LAYER_NAMES = {
        hfc_listings:    'HFC Listing',
        sold_comps:      'Sold Comp',
        active_listings: 'Active Listing',
        mic_subjects:    'MIC Subject',
        scheme_owners:   'Sectional Scheme',
    };

    // Composite pin palette — neutral slate so it reads as "multiple sources here"
    // and never collides with any single-category colour.
    const COMPOSITE_BG     = '#334155'; // slate-700
    const COMPOSITE_BORDER = '#00d4aa'; // teal accent — same as HFC, marks it as workspace UI

    // ── State ─────────────────────────────────────────────────────────────
    let viewMode = localStorage.getItem('corex.map.view_mode') || 'agent';
    let baseLayerKey = localStorage.getItem('corex.map.base_layer') || 'streets';

    // Phase 3h Step 10 — demo-data toggle. Default comes from a data attr
    // on the checkbox so the server can decide ON/OFF per environment.
    const demoToggleEl = document.getElementById('demo-toggle');
    const demoDefaultOn = (demoToggleEl?.dataset.defaultOn ?? '1') === '1';
    let includeDemo = (function () {
        const stored = localStorage.getItem('corex.map.include_demo');
        if (stored === '0') return false;
        if (stored === '1') return true;
        return demoDefaultOn;
    })();
    if (demoToggleEl) demoToggleEl.checked = includeDemo;

    // Phase 3g V2 — display mode + filter state.
    const CURRENT_YEAR = {{ now()->year }};
    const FILTER_DEFAULTS = {
        yearFrom: CURRENT_YEAR - 5,
        yearTo:   CURRENT_YEAR,
        types:    ['house','sectional','townhouse','vacant'],
        priceMin: 0,
        priceMax: 10000000,
        bedrooms: [1,2,3,4,5],
    };
    let displayMode = localStorage.getItem('corex.map.display_mode') || 'pins';
    let filters = loadFiltersFromStorage();
    let heatLayer = null;

    function loadFiltersFromStorage() {
        try {
            const raw = localStorage.getItem('corex.map.filters_v1');
            if (!raw) return { ...FILTER_DEFAULTS };
            const f = JSON.parse(raw);
            return {
                yearFrom: Number.isInteger(f.yearFrom) ? f.yearFrom : FILTER_DEFAULTS.yearFrom,
                yearTo:   Number.isInteger(f.yearTo)   ? f.yearTo   : FILTER_DEFAULTS.yearTo,
                types:    Array.isArray(f.types)    && f.types.length    ? f.types    : [...FILTER_DEFAULTS.types],
                priceMin: Number.isInteger(f.priceMin) ? f.priceMin : FILTER_DEFAULTS.priceMin,
                priceMax: Number.isInteger(f.priceMax) ? f.priceMax : FILTER_DEFAULTS.priceMax,
                bedrooms: Array.isArray(f.bedrooms) && f.bedrooms.length ? f.bedrooms : [...FILTER_DEFAULTS.bedrooms],
            };
        } catch (e) {
            return { ...FILTER_DEFAULTS };
        }
    }
    function persistFilters() {
        localStorage.setItem('corex.map.filters_v1', JSON.stringify(filters));
    }
    function countActiveFilters() {
        let n = 0;
        if (filters.yearFrom !== FILTER_DEFAULTS.yearFrom) n++;
        if (filters.yearTo   !== FILTER_DEFAULTS.yearTo)   n++;
        if (filters.types.length !== FILTER_DEFAULTS.types.length) n++;
        if (filters.priceMin !== FILTER_DEFAULTS.priceMin) n++;
        if (filters.priceMax !== FILTER_DEFAULTS.priceMax) n++;
        if (filters.bedrooms.length !== FILTER_DEFAULTS.bedrooms.length) n++;
        return n;
    }
    function syncFilterUi() {
        document.getElementById('filter-year-from').value = filters.yearFrom;
        document.getElementById('filter-year-to').value   = filters.yearTo;
        document.querySelectorAll('[data-filter-type]').forEach(cb => {
            cb.checked = filters.types.includes(cb.dataset.filterType);
        });
        document.getElementById('filter-price-min').value = filters.priceMin;
        document.getElementById('filter-price-max').value = filters.priceMax;
        document.querySelectorAll('#filter-bedrooms .bed-pill').forEach(btn => {
            const on = filters.bedrooms.includes(parseInt(btn.dataset.bed, 10));
            btn.dataset.on = on ? '1' : '0';
            btn.style.background = on ? 'var(--brand-button)' : 'var(--surface-2)';
            btn.style.color = on ? '#fff' : 'var(--text-secondary)';
        });
        const cnt = countActiveFilters();
        const badge = document.getElementById('filters-count');
        badge.textContent = String(cnt);
        badge.style.display = cnt > 0 ? 'inline-block' : 'none';
    }
    const enabledLayers = new Set([
        'hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners',
    ]);
    // Phase A.1 — server pre-groups co-located records into composite
    // locations; the client uses ONE clustering group across all categories.
    // The per-layer cluster groups from V1 are gone — they had no role once
    // grouping moved server-side.
    let cluster = null;
    const cache = []; // [{ key, payload }]
    let fetchTimer = null;
    let inFlight = null;
    let lastPayload = null; // kept for layer-toggle re-render without refetch

    // ── Map init ──────────────────────────────────────────────────────────
    const map = L.map('corex-map', { zoomControl: true, attributionControl: true }).fitBounds([
        [HFC_BOUNDS.south, HFC_BOUNDS.west],
        [HFC_BOUNDS.north, HFC_BOUNDS.east],
    ]);

    const streetsLayer = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap contributors',
        maxZoom: 19,
    });
    const satelliteLayer = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {
        attribution: 'Tiles © Esri',
        maxZoom: 19,
    });
    const baseLayers = { streets: streetsLayer, satellite: satelliteLayer };
    baseLayers[baseLayerKey].addTo(map);

    // Single MarkerClusterGroup — at low zoom geographically nearby composite
    // pins merge visually, at zoom ≥ 14 they un-cluster. Spiderfy DISABLED:
    // composite pins are already "the spider" — exploding them at max zoom
    // would defeat the whole grouping (a sectional title scheme has dozens
    // of units sharing one street address).
    cluster = L.markerClusterGroup({
        disableClusteringAtZoom: 14,
        maxClusterRadius: 40,
        chunkedLoading: true,
        spiderfyOnMaxZoom: false,
        showCoverageOnHover: false,
        zoomToBoundsOnClick: true,
    });
    cluster.addTo(map);

    // ── Helpers ───────────────────────────────────────────────────────────
    /**
     * Build the Leaflet icon for a location. Two paths:
     *   - composite (record_count > 1): neutral slate background + teal
     *     accent border + white count badge top-right
     *   - single record: category-coloured circle + category letter
     */
    function locationIcon(loc) {
        if (loc.is_composite) {
            const count = loc.record_count;
            const html =
                '<div style="position:relative;display:flex;align-items:center;justify-content:center;width:24px;height:24px;background:'
                + COMPOSITE_BG + ';color:#fff;border:2px solid ' + COMPOSITE_BORDER + ';border-radius:6px;font-size:9px;font-weight:700;box-shadow:0 1px 3px rgba(0,0,0,.4);">'
                +   '<span style="font-size:10px;letter-spacing:0.5px;">+</span>'
                + '</div>'
                + '<span style="position:absolute;top:-6px;right:-8px;background:#ffffff;color:#0f172a;font-size:10px;font-weight:700;border-radius:10px;padding:1px 5px;line-height:1;box-shadow:0 1px 2px rgba(0,0,0,.35);white-space:nowrap;border:1px solid ' + COMPOSITE_BORDER + ';">'
                + count + '</span>';

            return L.divIcon({
                html: '<div style="position:relative;width:24px;height:24px;">' + html + '</div>',
                className: 'corex-pin corex-pin-composite',
                iconSize: [24, 24],
                iconAnchor: [12, 12],
            });
        }

        // Single record — colour by its category.
        const rec = loc.records[0] || {};
        const colour = LAYER_COLOURS[rec.category] || '#64748b';
        const letter = LAYER_LETTERS[rec.category] || '?';
        const html = '<div style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;background:'
            + colour + ';color:#fff;border:2px solid #ffffff;border-radius:50%;font-size:11px;font-weight:700;box-shadow:0 1px 3px rgba(0,0,0,.4);">'
            + letter + '</div>';
        return L.divIcon({ html: html, className: 'corex-pin', iconSize: [22, 22], iconAnchor: [11, 11] });
    }

    /**
     * Apply the left-rail layer toggles to a server payload. Returns a
     * filtered copy that:
     *   - removes records[] entries whose category is disabled
     *   - drops locations whose records all got filtered out
     *   - re-derives is_composite + record_count + primary_category from the
     *     remaining records
     */
    function applyLayerFilters(payload) {
        const out = [];
        (payload.locations || []).forEach(loc => {
            const kept = (loc.records || []).filter(r => enabledLayers.has(r.category));
            if (kept.length === 0) return;
            out.push({
                ...loc,
                records: kept,
                record_count: kept.length,
                is_composite: kept.length > 1,
                primary_category: kept[0].category,
                categories_present: Array.from(new Set(kept.map(r => r.category))),
            });
        });
        return { ...payload, locations: out };
    }

    /**
     * Phase A.2 — derive the available actions for a record from its category.
     * Returns an array of action descriptors. Each one is enough to render
     * BOTH a CTA button (single_detail) and a quick-icon (composite_row).
     *
     * Shape:
     *   {
     *     key:        'pitch_launched' | 'whatsapp_launched' | ... ,
     *     label:      'Pitch this property →',  // CTA button text
     *     iconLabel:  'WhatsApp this property',  // quick-icon tooltip
     *     iconSvg:    '<svg ...>',               // 16x16 inline svg
     *     style:      'primary' | 'secondary',
     *     destUrl:    '/corex/properties/123',   // navigation target
     *     logPayload: { action, category, record_id, source, location_key }
     *   }
     */
    function actionsForRecord(record, sourceContext, locationKey) {
        const recId = record.id;
        const baseLog = {
            category:     record.category,
            location_key: locationKey,
            source:       sourceContext, // 'single_detail' | 'composite_row'
        };

        switch (record.category) {
            case 'hfc_listings': {
                // A.2.1 — "Open listing →" smart-picks the activated portal URL
                // (P24 → PP → HFC website) served by Property::preferredPublicListingUrl().
                // When no external URL exists (most demo data is unsyndicated)
                // we fall through to "Open record →" pointing at the internal page.
                if (typeof recId !== 'number') return [];
                const portalUrl   = record.preferred_public_url || null;
                const internalUrl = record.internal_url || PROPERTY_SHOW_URL_TPL.replace('__ID__', String(recId));
                const isSold      = record.status === 'sold';

                if (portalUrl && !isSold) {
                    return [{
                        key:       'pitch_launched',
                        label:     'Open listing →',
                        iconLabel: 'Open listing on portal',
                        iconSvg:   ICON_OPEN,
                        style:     'primary',
                        destUrl:   portalUrl,
                        newTab:    true,
                        logPayload:{ ...baseLog, action: 'pitch_launched', record_id: recId },
                    }];
                }
                // Sold or unsyndicated — internal record only.
                return [{
                    key:       'pitch_launched',
                    label:     isSold ? 'Open record →' : 'Open record →',
                    iconLabel: 'Open property record',
                    iconSvg:   ICON_OPEN,
                    style:     'secondary',
                    destUrl:   internalUrl,
                    newTab:    false,
                    logPayload:{ ...baseLog, action: 'pitch_launched', record_id: recId },
                }];
            }

            case 'sold_comps': {
                // A.2.1 — "Open evaluation →" deep-links to the comp's source
                // market report. Returns null when no parent report (e.g. deals).
                const reportId = record.parent_report_id || null;
                const url = reportId ? MIC_REPORT_SHOW_TPL.replace('__ID__', String(reportId)) : null;
                return [{
                    key:       'cma_opened',
                    label:     'Open evaluation →',
                    iconLabel: 'Open evaluation report',
                    iconSvg:   ICON_OPEN,
                    style:     'secondary',
                    destUrl:   url,
                    newTab:    true,
                    // The activity event is still cma_opened because that's
                    // semantically what the click does — opens the evaluation
                    // report. The record_id we log is the integer report id.
                    logPayload: reportId
                        ? { ...baseLog, action: 'cma_opened', record_id: reportId }
                        : { ...baseLog, action: 'comparable_added', record_id: String(recId) },
                }];
            }

            case 'active_listings': {
                // A.2.1 — "Prospect Now →" lands in MIC Opportunities for the
                // matching TrackedProperty. The server resolves the tp_id from
                // address/GPS via TrackedPropertyMatchOrCreateService and
                // returns redirect_url in the activity-log response.
                return [{
                    key:       'prospect_launched',
                    label:     'Prospect Now →',
                    iconLabel: 'Prospect this property',
                    iconSvg:   ICON_FIND,
                    style:     'primary',
                    destUrl:   MIC_OPPORTUNITIES_URL, // fallback if server doesn't resolve a tp
                    newTab:    false,
                    awaitServerRedirect: true, // tells the click handler to wait for redirect_url
                    logPayload: {
                        ...baseLog,
                        action:              'prospect_launched',
                        record_id:           String(recId),
                        tracked_property_id: record.tracked_property_id ?? null,
                        address:             record.title || null,
                        latitude:            record.lat ?? null,
                        longitude:           record.lng ?? null,
                        suburb:              record.suburb || null,
                    },
                }];
            }

            case 'mic_subjects': {
                if (typeof recId !== 'number') return [];
                const reportUrl = MIC_REPORT_SHOW_TPL.replace('__ID__', String(recId));
                return [{
                    key:       'cma_opened',
                    label:     'Open evaluation →',
                    iconLabel: 'Open evaluation',
                    iconSvg:   ICON_OPEN,
                    style:     'primary',
                    destUrl:   reportUrl,
                    newTab:    true,
                    logPayload:{ ...baseLog, action: 'cma_opened', record_id: recId },
                }];
            }

            case 'scheme_owners': {
                if (typeof recId !== 'number') return [];
                // Scheme owners aren't Contacts yet — open a wa.me deep link
                // when the source carries a phone. If not, the button is
                // disabled (destUrl=null) and the agent uses the right panel
                // detail to copy the contact details manually.
                const phone = record.owner_phone || null;
                const waText = encodeURIComponent('Hi — I noticed your unit at ' + (record.title || 'your building') + ' and wondered if you have a moment to chat.');
                const waUrl  = phone ? 'https://wa.me/' + phone.replace(/\D/g, '') + '?text=' + waText : null;
                return [{
                    key:       'contact_owner_launched',
                    label:     'Contact owner →',
                    iconLabel: 'Contact owner',
                    iconSvg:   ICON_WHATSAPP,
                    style:     'primary',
                    destUrl:   waUrl,
                    newTab:    false,
                    logPayload:{ ...baseLog, action: 'contact_owner_launched', record_id: recId, channel: 'whatsapp' },
                }];
            }
        }
        return [];
    }

    /**
     * Fire-and-forget POST to the map activity log endpoint. NEVER blocks
     * the user from navigating — caller invokes this then proceeds.
     */
    function fireActivityLog(payload) {
        try {
            fetch(MAP_ACTIVITY_URL, {
                method:      'POST',
                headers:     {
                    'Accept':       'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': CSRF_TOKEN,
                },
                credentials: 'same-origin',
                keepalive:   true,
                body:        JSON.stringify(payload),
            }).catch(() => {}); // silent
        } catch (e) { /* swallow */ }
    }

    // Inline SVGs — 16x16, currentColor. Kept inline so we don't depend on
    // an icon font load order on the standalone map page.
    const ICON_PITCH      = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4 20-7Z"></path></svg>';
    const ICON_WHATSAPP   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"></path></svg>';
    const ICON_COMPARABLE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"></path><path d="M3 9h18M9 3v18"></path></svg>';
    const ICON_FIND       = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>';
    const ICON_OPEN       = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><path d="M15 3h6v6"></path><path d="m10 14 11-11"></path></svg>';

    function currentBounds() {
        const b = map.getBounds();
        return {
            north: b.getNorth(), south: b.getSouth(),
            east: b.getEast(),   west: b.getWest(),
        };
    }

    function boundsKey(b) {
        const filterFp = [
            filters.yearFrom, filters.yearTo,
            filters.types.slice().sort().join(','),
            filters.priceMin, filters.priceMax,
            filters.bedrooms.slice().sort().join(','),
        ].join(':');
        return [b.south, b.west, b.north, b.east, viewMode, includeDemo ? '1' : '0', displayMode, filterFp, Array.from(enabledLayers).sort().join(',')]
            .map(v => typeof v === 'number' ? v.toFixed(4) : v).join('|');
    }

    function setLoading(on) {
        document.getElementById('map-loading-pill').style.display = on ? 'inline-flex' : 'none';
    }

    function clearAllPins() {
        if (cluster) cluster.clearLayers();
    }

    function renderPayload(payload) {
        lastPayload = payload;
        clearAllPins();

        // Update left-rail counts from layer_counts (post-filter, pre-grouping).
        const counts = payload.layer_counts || {};
        Object.keys(LAYER_COLOURS).forEach(key => {
            const badge = document.querySelector('[data-layer-count="' + key + '"]');
            if (badge) badge.textContent = String(counts[key] ?? 0);
        });

        // Apply layer toggles client-side (re-derives composite flags).
        const filtered = applyLayerFilters(payload);

        const showPins = displayMode === 'pins' || displayMode === 'both';
        const showHeat = displayMode === 'heatmap' || displayMode === 'both';
        const heatPoints = [];

        let total = 0;
        (filtered.locations || []).forEach(loc => {
            heatPoints.push([loc.latitude, loc.longitude, 1.0]);
            total += loc.record_count;
            if (!showPins) return;

            const m = L.marker([loc.latitude, loc.longitude], { icon: locationIcon(loc) });
            const tooltipLines = [];
            if (loc.is_composite) {
                tooltipLines.push((loc.geocode_target || 'This address').toUpperCase());
                tooltipLines.push(loc.record_count + ' records here — click to list');
            } else {
                const rec = loc.records[0];
                tooltipLines.push(rec.title || '');
                if (rec.subtitle) tooltipLines.push(rec.subtitle);
            }
            m.bindTooltip(tooltipLines.join('\n'), { direction: 'top' });

            m.on('click', () => {
                if (loc.is_composite) {
                    openCompositeList(loc);
                } else {
                    // Single-record click — go straight to the detail view,
                    // no back arrow (there's no list to go back to).
                    openSingleDetail(loc.records[0], null, loc.location_key);
                }
            });
            cluster.addLayer(m);
        });

        document.getElementById('empty-state').style.display = total === 0 ? 'block' : 'none';

        // Capped-layers warning — reuses the V1 banner.
        const capEl = document.getElementById('layer-cap-notice');
        const capped = payload.capped_layers || [];
        if (capped.length > 0) {
            capEl.textContent = 'Truncated: ' + capped.join(', ');
            capEl.style.display = 'block';
        } else {
            capEl.style.display = 'none';
        }

        renderHeatmap(showHeat ? heatPoints : []);
        document.getElementById('heat-legend').style.display = showHeat ? 'block' : 'none';
    }

    function renderHeatmap(points) {
        if (points.length === 0) {
            if (heatLayer) { map.removeLayer(heatLayer); heatLayer = null; }
            return;
        }
        if (!heatLayer) {
            heatLayer = L.heatLayer(points, {
                radius: 25,
                blur: 18,
                maxZoom: 17,
                max: 5.0,
                // Inverted gradient — red where data is THIN (action needed),
                // green where coverage is dense. Reads against operator instinct
                // ("hot" usually = warning) but matches our actual goal: red
                // means "pull more CMA reports here".
                gradient: {
                    0.0: '#dc2626',
                    0.3: '#f59e0b',
                    0.6: '#84cc16',
                    1.0: '#16a34a',
                },
            }).addTo(map);
        } else {
            heatLayer.setLatLngs(points);
            if (!map.hasLayer(heatLayer)) heatLayer.addTo(map);
        }
    }

    // ── Fetching ──────────────────────────────────────────────────────────
    async function fetchPins() {
        if (inFlight) inFlight.abort();
        const b = currentBounds();
        const key = boundsKey(b);

        // Cache hit
        const hit = cache.find(c => c.key === key);
        if (hit) {
            renderPayload(hit.payload);
            return;
        }

        const params = new URLSearchParams({
            north: b.north.toFixed(6),
            south: b.south.toFixed(6),
            east:  b.east.toFixed(6),
            west:  b.west.toFixed(6),
            viewMode: viewMode,
            limit: '2000',
            include_demo: includeDemo ? '1' : '0',
        });
        // Always send all five layer keys so server gives counts for each;
        // the UI hides disabled ones at render time.
        ['hfc_listings','sold_comps','active_listings','mic_subjects','scheme_owners'].forEach(k => params.append('layers[]', k));

        // Filters — only send when narrowed beyond defaults so the URL stays
        // clean in the common case.
        if (filters.yearFrom !== FILTER_DEFAULTS.yearFrom) params.set('dateFromYear', String(filters.yearFrom));
        if (filters.yearTo   !== FILTER_DEFAULTS.yearTo)   params.set('dateToYear',   String(filters.yearTo));
        if (filters.types.length !== FILTER_DEFAULTS.types.length) {
            filters.types.forEach(t => params.append('propertyTypes[]', t));
        }
        if (filters.priceMin !== FILTER_DEFAULTS.priceMin) params.set('priceMin', String(filters.priceMin));
        if (filters.priceMax !== FILTER_DEFAULTS.priceMax) params.set('priceMax', String(filters.priceMax));
        if (filters.bedrooms.length !== FILTER_DEFAULTS.bedrooms.length) {
            filters.bedrooms.forEach(b => params.append('bedrooms[]', String(b)));
        }

        setLoading(true);
        try {
            const ctl = new AbortController();
            inFlight = ctl;
            const resp = await fetch(PINS_URL + '?' + params.toString(), {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                signal: ctl.signal,
            });
            if (!resp.ok) throw new Error('HTTP ' + resp.status);
            const payload = await resp.json();
            inFlight = null;

            // LRU cache
            cache.push({ key: key, payload: payload });
            if (cache.length > CACHE_MAX) cache.shift();

            renderPayload(payload);
        } catch (e) {
            if (e.name === 'AbortError') return; // superseded by newer fetch
            console.warn('Map fetch error', e);
        } finally {
            setLoading(false);
        }
    }

    function debouncedFetch() {
        clearTimeout(fetchTimer);
        fetchTimer = setTimeout(fetchPins, 350);
    }

    // ── Right panel state machine ─────────────────────────────────────────
    // States: 'composite_list' | 'single_detail'. Closing the panel resets
    // to no state. When opening single_detail from composite_list we keep a
    // reference to the parent location so the back arrow can return.
    const detailPanel = document.getElementById('map-detail-panel');
    let panelParentComposite = null; // last composite_list location (for back-nav)

    function setPanelOpen(open) {
        detailPanel.style.transform = open ? 'translateX(0)' : 'translateX(100%)';
    }

    document.getElementById('detail-close-btn').addEventListener('click', () => {
        setPanelOpen(false);
        panelParentComposite = null;
        document.getElementById('detail-back-btn').style.display = 'none';
    });

    document.getElementById('detail-back-btn').addEventListener('click', () => {
        if (panelParentComposite) openCompositeList(panelParentComposite);
    });

    /**
     * Render the composite list — every record at this location, each
     * clickable to drill into single_detail with a back arrow.
     */
    function openCompositeList(loc) {
        panelParentComposite = loc;

        // A.2.1 — header reframe. When every record at this location is a
        // sectional scheme owner, headline becomes the scheme name + "N units"
        // — feels like a building card, not a coordinate list. Otherwise the
        // canonical street address.
        const allSchemeOwners = (loc.records || []).every(r => r.category === 'scheme_owners');
        let headline, subline;
        if (allSchemeOwners && loc.records.length > 0) {
            const schemeName = String(loc.records[0].title || '').split(' § ')[0].trim() || 'Sectional Scheme';
            headline = schemeName.toUpperCase();
            subline  = loc.record_count + ' unit' + (loc.record_count === 1 ? '' : 's');
        } else {
            headline = (loc.geocode_target || loc.records[0]?.title || '').toUpperCase();
            subline  = loc.record_count + ' record' + (loc.record_count === 1 ? '' : 's') + ' at this address';
        }
        document.getElementById('detail-title').textContent = headline;
        document.getElementById('detail-subtitle').textContent = subline;

        // Show composite view, hide single view.
        document.getElementById('detail-composite-list').style.display = 'block';
        document.getElementById('detail-single').style.display = 'none';
        document.getElementById('detail-back-btn').style.display = 'none';

        // Group records by category for grouped rendering.
        const byCategory = {};
        loc.records.forEach(rec => {
            (byCategory[rec.category] = byCategory[rec.category] || []).push(rec);
        });

        let html = '';
        Object.keys(byCategory).forEach(cat => {
            const colour = LAYER_COLOURS[cat] || '#64748b';
            const letter = LAYER_LETTERS[cat] || '?';
            const name   = LAYER_NAMES[cat]   || cat;
            const recs   = byCategory[cat];

            html += '<div style="margin-bottom:14px;">'
                +    '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;">'
                +      '<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;background:' + colour + ';color:#fff;border-radius:50%;font-size:9px;font-weight:700;">' + letter + '</span>'
                +      escapeHtml(name) + ' · ' + recs.length
                +    '</div>';

            recs.forEach((rec, idx) => {
                const globalIdx = loc.records.indexOf(rec);
                // Phase A.2 — quick-action icons per category. The row itself
                // still drills into single_detail; the icons fire activity-log
                // POSTs and navigate without entering the panel state machine.
                const actions = actionsForRecord(rec, 'composite_row', loc.location_key);
                const iconStrip = actions.map((act, aIdx) =>
                    '<a href="' + escapeAttr(act.destUrl || '#') + '" '
                    +    'data-map-action="' + escapeAttr(act.key) + '" '
                    +    'data-action-idx="' + globalIdx + ':' + aIdx + '" '
                    +    'title="' + escapeAttr(act.iconLabel) + '" '
                    +    (act.newTab ? 'target="_blank" rel="noopener" ' : '')
                    +    (act.destUrl ? '' : 'aria-disabled="true" ')
                    +    'style="display:inline-flex;align-items:center;justify-content:center;width:28px;height:28px;border-radius:4px;color:var(--text-muted);text-decoration:none;transition:all 150ms;" '
                    +    'onmouseover="this.style.color=\'#00d4aa\';this.style.background=\'color-mix(in srgb, #00d4aa 10%, transparent)\';" '
                    +    'onmouseout="this.style.color=\'var(--text-muted)\';this.style.background=\'transparent\';">'
                    +    act.iconSvg
                    + '</a>'
                ).join('');

                html += '<div data-record-row="' + globalIdx + '" '
                    +     'style="display:flex;align-items:center;gap:10px;width:100%;padding:10px 12px;margin-bottom:6px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;font-family:inherit;">'
                    +     '<button type="button" data-record-idx="' + globalIdx + '" '
                    +       'style="display:flex;flex:1;min-width:0;background:transparent;border:0;text-align:left;cursor:pointer;font-family:inherit;padding:0;">'
                    +       '<span style="flex:1;min-width:0;">'
                    +         '<div style="font-size:0.8125rem;font-weight:500;color:var(--text-primary);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(rec.title || '') + '</div>'
                    +         (rec.subtitle ? '<div style="font-size:0.6875rem;color:var(--text-muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px;">' + escapeHtml(rec.subtitle) + '</div>' : '')
                    +       '</span>'
                    +     '</button>'
                    +     (iconStrip ? '<span style="display:inline-flex;gap:2px;flex-shrink:0;" data-quick-actions="' + globalIdx + '">' + iconStrip + '</span>' : '')
                    +     '<span style="color:var(--text-muted);font-size:0.875rem;flex-shrink:0;">→</span>'
                    + '</div>';
            });
            html += '</div>';
        });

        document.getElementById('composite-records').innerHTML = html;

        // Wire row clicks → single_detail (icons handled separately below).
        document.querySelectorAll('[data-record-idx]').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.recordIdx, 10);
                openSingleDetail(loc.records[idx], loc, loc.location_key);
            });
        });

        // Wire quick-icon clicks — fire activity log, then default <a>
        // behaviour navigates. stopPropagation prevents the parent row from
        // also opening the panel.
        document.querySelectorAll('[data-map-action]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.stopPropagation();
                // record_id sits on the action descriptor (via data-action-idx
                // → records[recIdx] → actions[aIdx]); re-derive cheaply.
                const [rIdx, aIdx] = link.dataset.actionIdx.split(':').map(n => parseInt(n, 10));
                const rec = loc.records[rIdx];
                const acts = actionsForRecord(rec, 'composite_row', loc.location_key);
                const act  = acts[aIdx];
                if (!act) return;
                handleActionClick(e, act);
            });
        });

        setPanelOpen(true);
    }

    /**
     * Render single-record detail. When opened from a composite, `parent`
     * carries the parent location so the back arrow can restore the list.
     * `parent=null` for direct single-pin clicks (no back arrow shown).
     * `locationKey` powers the Phase A.2 activity log on action launches.
     */
    async function openSingleDetail(record, parent, locationKey) {
        panelParentComposite = parent;
        const backBtn = document.getElementById('detail-back-btn');
        if (parent) {
            backBtn.style.display = 'flex';
            const schemeOnly = (parent.records || []).every(r => r.category === 'scheme_owners');
            let back;
            if (schemeOnly && parent.records.length > 0) {
                const schemeName = String(parent.records[0].title || '').split(' § ')[0].trim() || 'Sectional Scheme';
                back = parent.record_count + ' unit' + (parent.record_count === 1 ? '' : 's')
                    + ' at ' + schemeName;
            } else {
                back = parent.record_count + ' record' + (parent.record_count === 1 ? '' : 's')
                    + ' at ' + (parent.geocode_target || parent.records[0]?.title || 'this address');
            }
            document.getElementById('detail-back-label').textContent = back;
        } else {
            backBtn.style.display = 'none';
        }

        document.getElementById('detail-composite-list').style.display = 'none';
        document.getElementById('detail-single').style.display = 'block';

        document.getElementById('detail-title').textContent = record.title || '';
        document.getElementById('detail-subtitle').textContent = record.subtitle || '';
        document.getElementById('detail-facts').innerHTML = '<div style="font-size:0.75rem;color:var(--text-muted);">Loading…</div>';
        document.getElementById('detail-relationships').innerHTML = '';
        document.getElementById('detail-sensitive').style.display = 'none';
        document.getElementById('detail-address').style.display = 'none';
        document.getElementById('detail-ctas').innerHTML = '';
        setPanelOpen(true);

        const url = record.deep_link || record.detail_url;
        if (url) {
            try {
                const fullUrl = url + (url.includes('?') ? '&' : '?') + 'viewMode=' + viewMode;
                const resp = await fetch(fullUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (resp.ok) {
                    renderCard(await resp.json());
                } else {
                    document.getElementById('detail-facts').innerHTML =
                        '<div style="color:var(--ds-red,#dc2626);font-size:0.75rem;">Could not load details.</div>';
                }
            } catch (e) {
                document.getElementById('detail-facts').innerHTML =
                    '<div style="color:var(--ds-red,#dc2626);font-size:0.75rem;">' + escapeHtml(e.message || 'Error') + '</div>';
            }
        } else {
            document.getElementById('detail-facts').innerHTML =
                '<div style="font-size:0.75rem;color:var(--text-muted);">No detail card available for this record.</div>';
        }

        // Phase A.2 — CTAs render regardless of card load success.
        renderSingleDetailCtas(record, locationKey);
    }

    /**
     * Render the primary/secondary CTAs in the single-detail panel and wire
     * each one to fire its activity log POST + default <a> navigation.
     */
    function renderSingleDetailCtas(record, locationKey) {
        const acts = actionsForRecord(record, 'single_detail', locationKey);
        const host = document.getElementById('detail-ctas');
        if (acts.length === 0) {
            host.innerHTML = '';
            return;
        }
        host.innerHTML = acts.map(act => {
            const stylePrimary = 'background:#00d4aa;color:#0f172a;border:1px solid #00d4aa;';
            const styleSecondary = 'background:transparent;color:#00d4aa;border:1px solid #00d4aa;';
            const styleStr = act.style === 'secondary' ? styleSecondary : stylePrimary;
            const disabled = !act.destUrl && !act.awaitServerRedirect;
            return '<a href="' + escapeAttr(act.destUrl || '#') + '" '
                + 'data-map-action="' + escapeAttr(act.key) + '" '
                + (act.newTab ? 'target="_blank" rel="noopener" ' : '')
                + (disabled ? 'aria-disabled="true" ' : '')
                + 'style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:40px;padding:8px 12px;border-radius:6px;font-size:0.8125rem;font-weight:600;text-decoration:none;transition:opacity 150ms;'
                +    styleStr + (disabled ? 'opacity:0.5;cursor:not-allowed;' : '') + '">'
                + '<span style="display:inline-flex;align-items:center;">' + act.iconSvg + '</span>'
                + '<span>' + escapeHtml(act.label) + '</span>'
                + '</a>';
        }).join('');

        // Wire activity-log firing on click. Default <a> handles tab vs same-tab.
        host.querySelectorAll('[data-map-action]').forEach((link, idx) => {
            const act = acts[idx];
            link.addEventListener('click', (e) => handleActionClick(e, act));
        });
    }

    /**
     * Unified click handler for both the composite-row icon strip and the
     * single-detail CTA buttons. Three paths:
     *  1) act.awaitServerRedirect=true (prospect_launched) — block default
     *     navigation, fire activity log synchronously, await the server's
     *     redirect_url (tracked-property match-or-create result), then
     *     navigate. Fallback to destUrl when the server doesn't return one.
     *  2) act.destUrl present — fire activity log fire-and-forget and let
     *     the browser's default <a> navigation proceed.
     *  3) No destUrl — preventDefault (link is disabled).
     */
    async function handleActionClick(e, act) {
        if (act.awaitServerRedirect) {
            e.preventDefault();
            try {
                const resp = await fetch(MAP_ACTIVITY_URL, {
                    method:      'POST',
                    headers:     {
                        'Accept':       'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': CSRF_TOKEN,
                    },
                    credentials: 'same-origin',
                    body:        JSON.stringify(act.logPayload),
                });
                if (resp.ok) {
                    const body = await resp.json();
                    const url = body.redirect_url || act.destUrl;
                    if (url) {
                        if (act.newTab) window.open(url, '_blank', 'noopener');
                        else window.location.href = url;
                        return;
                    }
                }
            } catch (err) { /* fall through to destUrl fallback */ }
            if (act.destUrl) {
                if (act.newTab) window.open(act.destUrl, '_blank', 'noopener');
                else window.location.href = act.destUrl;
            }
            return;
        }
        if (!act.destUrl) { e.preventDefault(); return; }
        fireActivityLog(act.logPayload);
        // newTab handled by the anchor's target=_blank attribute; default
        // <a> navigation continues.
    }

    function renderCard(card) {
        document.getElementById('detail-title').textContent = card.title || '';
        document.getElementById('detail-subtitle').textContent = card.subtitle || '';

        const addrEl = document.getElementById('detail-address');
        if (card.address) {
            addrEl.textContent = card.address;
            addrEl.style.display = 'block';
        }

        const factsHtml = (card.facts || []).map(f =>
            '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:0.8125rem;">'
            + '<span style="color:var(--text-muted);">' + escapeHtml(f.label) + '</span>'
            + '<span style="color:var(--text-primary);font-weight:500;">' + escapeHtml(String(f.value)) + '</span>'
            + '</div>'
        ).join('');
        document.getElementById('detail-facts').innerHTML = factsHtml
            || '<div style="font-size:0.75rem;color:var(--text-muted);">No facts available.</div>';

        if (card.sensitive_facts && card.sensitive_facts.length > 0) {
            const sensitiveHtml = card.sensitive_facts.map(f =>
                '<div style="display:flex;justify-content:space-between;padding:4px 0;font-size:0.8125rem;">'
                + '<span style="color:var(--text-muted);">' + escapeHtml(f.label) + '</span>'
                + '<span style="color:var(--text-primary);font-weight:500;">' + escapeHtml(String(f.value)) + '</span>'
                + '</div>'
            ).join('');
            document.getElementById('detail-sensitive-facts').innerHTML = sensitiveHtml;
            document.getElementById('detail-sensitive').style.display = 'block';
        }

        const relsHtml = (card.relationships || []).map(r =>
            '<a href="' + escapeAttr(r.url) + '" style="display:block;padding:8px 10px;margin-bottom:6px;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;font-size:0.8125rem;color:var(--brand-button);text-decoration:none;">'
            + escapeHtml(r.label) + ' →</a>'
        ).join('');
        document.getElementById('detail-relationships').innerHTML = relsHtml;
    }

    function escapeHtml(s) { return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
    function escapeAttr(s) { return escapeHtml(s); }

    // ── Toggles ───────────────────────────────────────────────────────────
    document.querySelectorAll('[data-layer-cb]').forEach(cb => {
        cb.addEventListener('change', e => {
            const key = e.target.dataset.layerCb;
            if (e.target.checked) enabledLayers.add(key);
            else enabledLayers.delete(key);

            // A.1.2 fix — triple-defence so a toggle never visibly fails:
            // (1) immediate client-side re-render from the last payload so
            //     the user sees the change inside one frame,
            // (2) bust the LRU cache because boundsKey includes the
            //     enabledLayers fingerprint and the response shape changes
            //     when layers are excluded,
            // (3) trigger a fresh fetch so the server-side filter is the
            //     authoritative source of truth on the next bounds change.
            if (lastPayload) renderPayload(lastPayload);
            cache.length = 0;
            fetchPins();
        });
    });

    document.querySelectorAll('#base-layer-toggle .base-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.base;
            if (target === baseLayerKey) return;
            map.removeLayer(baseLayers[baseLayerKey]);
            baseLayers[target].addTo(map);
            baseLayerKey = target;
            localStorage.setItem('corex.map.base_layer', target);
            // Style refresh
            document.querySelectorAll('#base-layer-toggle .base-pill').forEach(b => {
                const active = b.dataset.base === target;
                b.classList.toggle('active', active);
                b.style.background = active ? 'var(--brand-button)' : 'transparent';
                b.style.color = active ? '#fff' : 'var(--text-secondary)';
            });
        });
    });

    document.querySelectorAll('#view-mode-toggle .mode-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.mode;
            if (target === viewMode) return;
            viewMode = target;
            localStorage.setItem('corex.map.view_mode', target);
            document.getElementById('seller-banner').style.display = target === 'seller' ? 'block' : 'none';
            document.querySelectorAll('#view-mode-toggle .mode-pill').forEach(b => {
                const active = b.dataset.mode === target;
                b.classList.toggle('active', active);
                b.style.background = active ? 'var(--brand-button)' : 'transparent';
                b.style.color = active ? '#fff' : 'var(--text-secondary)';
            });
            // Hide scheme_owners layer entry in seller view (it'll be empty anyway).
            document.querySelectorAll('[data-sensitive="1"]').forEach(el => {
                el.style.display = target === 'seller' ? 'none' : 'flex';
            });
            // Clear cache (server response shape differs) + refetch.
            cache.length = 0;
            fetchPins();
        });
    });

    document.getElementById('reset-bounds-btn').addEventListener('click', () => {
        map.fitBounds([[HFC_BOUNDS.south, HFC_BOUNDS.west], [HFC_BOUNDS.north, HFC_BOUNDS.east]]);
    });

    // Phase 3g V2 — display-mode radios.
    document.querySelectorAll('#display-mode-group input[type="radio"]').forEach(r => {
        if (r.value === displayMode) r.checked = true;
        r.addEventListener('change', e => {
            if (!e.target.checked) return;
            displayMode = e.target.value;
            localStorage.setItem('corex.map.display_mode', displayMode);
            cache.length = 0;
            fetchPins();
        });
    });

    // Phase 3g V2 — filters: collapse toggle + change handlers + clear button.
    const filtersToggleBtn = document.getElementById('filters-toggle');
    const filtersBody      = document.getElementById('filters-body');
    const filtersCaret     = document.getElementById('filters-caret');
    filtersToggleBtn.addEventListener('click', () => {
        const open = filtersBody.style.display === 'block';
        filtersBody.style.display = open ? 'none' : 'block';
        filtersCaret.style.transform = open ? 'rotate(0deg)' : 'rotate(90deg)';
    });

    function onFilterChange() {
        const yf = parseInt(document.getElementById('filter-year-from').value, 10);
        const yt = parseInt(document.getElementById('filter-year-to').value, 10);
        const types = Array.from(document.querySelectorAll('[data-filter-type]'))
            .filter(cb => cb.checked).map(cb => cb.dataset.filterType);
        const pMin = parseInt(document.getElementById('filter-price-min').value, 10);
        const pMax = parseInt(document.getElementById('filter-price-max').value, 10);
        const beds = Array.from(document.querySelectorAll('#filter-bedrooms .bed-pill'))
            .filter(b => b.dataset.on === '1').map(b => parseInt(b.dataset.bed, 10));

        filters = {
            yearFrom: Number.isInteger(yf) ? yf : FILTER_DEFAULTS.yearFrom,
            yearTo:   Number.isInteger(yt) ? yt : FILTER_DEFAULTS.yearTo,
            types:    types.length ? types : [...FILTER_DEFAULTS.types],
            priceMin: Number.isInteger(pMin) ? pMin : FILTER_DEFAULTS.priceMin,
            priceMax: Number.isInteger(pMax) ? pMax : FILTER_DEFAULTS.priceMax,
            bedrooms: beds.length ? beds : [...FILTER_DEFAULTS.bedrooms],
        };
        persistFilters();
        syncFilterUi();
        cache.length = 0;
        fetchPins();
    }

    ['filter-year-from','filter-year-to','filter-price-min','filter-price-max'].forEach(id => {
        document.getElementById(id).addEventListener('change', onFilterChange);
    });
    document.querySelectorAll('[data-filter-type]').forEach(cb => cb.addEventListener('change', onFilterChange));
    document.querySelectorAll('#filter-bedrooms .bed-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            btn.dataset.on = btn.dataset.on === '1' ? '0' : '1';
            const on = btn.dataset.on === '1';
            btn.style.background = on ? 'var(--brand-button)' : 'var(--surface-2)';
            btn.style.color      = on ? '#fff' : 'var(--text-secondary)';
            onFilterChange();
        });
    });
    document.getElementById('filter-clear').addEventListener('click', () => {
        filters = { ...FILTER_DEFAULTS, types: [...FILTER_DEFAULTS.types], bedrooms: [...FILTER_DEFAULTS.bedrooms] };
        persistFilters();
        syncFilterUi();
        cache.length = 0;
        fetchPins();
    });

    syncFilterUi();

    // Phase 3h Step 10 — demo toggle click handler.
    if (demoToggleEl) {
        demoToggleEl.addEventListener('change', e => {
            includeDemo = !!e.target.checked;
            localStorage.setItem('corex.map.include_demo', includeDemo ? '1' : '0');
            cache.length = 0;
            fetchPins();
        });
    }

    map.on('moveend zoomend', debouncedFetch);

    // Initial render of seller view from persisted preference.
    if (viewMode === 'seller') {
        document.querySelectorAll('#view-mode-toggle .mode-pill').forEach(b => {
            const active = b.dataset.mode === viewMode;
            b.style.background = active ? 'var(--brand-button)' : 'transparent';
            b.style.color = active ? '#fff' : 'var(--text-secondary)';
        });
        document.getElementById('seller-banner').style.display = 'block';
        document.querySelectorAll('[data-sensitive="1"]').forEach(el => el.style.display = 'none');
    }
    if (baseLayerKey === 'satellite') {
        document.querySelectorAll('#base-layer-toggle .base-pill').forEach(b => {
            const active = b.dataset.base === baseLayerKey;
            b.style.background = active ? 'var(--brand-button)' : 'transparent';
            b.style.color = active ? '#fff' : 'var(--text-secondary)';
        });
    }

    // First fetch.
    fetchPins();
});
</script>
@endsection
