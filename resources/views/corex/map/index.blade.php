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
{{-- Map visual identity — selected-pin halo + shape-class radius overrides.
     Lives outside the inline body styles so it survives the Leaflet marker
     DOM reset on every fetchPins() — Leaflet writes marker.options.icon's
     className verbatim onto the .leaflet-marker-icon element, so the rule
     targets that class directly. The ring is layered OUTSIDE the existing
     2px white border via box-shadow so it doesn't resize the icon and
     clash with iconAnchor. z-index lifts the selected pin above its
     neighbours so the halo is never clipped by overlap. --}}
<style>
    .corex-pin {
        background: transparent !important;
        border: 0 !important;
    }
    .corex-pin.corex-pin--selected {
        box-shadow:
            0 0 0 3px #00d4aa,
            0 0 0 6px rgba(0, 212, 170, 0.35),
            0 1px 3px rgba(0, 0, 0, 0.4);
        z-index: 1000 !important;
        border-radius: 50%;
    }
    /* Sidebar-hover pulse — fix-up §5. Applied to the marker icon for ~1s
       when the agent hovers a composite-row sidebar card. Used in parallel
       with .corex-pin--selected, so the pulse animation must stack with
       any halo box-shadow already on the element. */
    @keyframes corex-pin-pulse {
        0%   { filter: drop-shadow(0 0 0 rgba(0, 212, 170, 0)); }
        40%  { filter: drop-shadow(0 0 8px rgba(0, 212, 170, 0.95)); }
        100% { filter: drop-shadow(0 0 0 rgba(0, 212, 170, 0)); }
    }
    .corex-pin.corex-pin--pulse {
        animation: corex-pin-pulse 1s ease-in-out 2;
    }
    /* Map → sidebar reverse-direction flash. Brief teal border tint on the
       detail panel root so the agent's eye is drawn to the freshly-opened
       card after a map-pin click. */
    @keyframes corex-panel-flash {
        0%   { box-shadow: 0 0 0 0 rgba(0, 212, 170, 0); }
        30%  { box-shadow: 0 0 0 3px rgba(0, 212, 170, 0.55); }
        100% { box-shadow: 0 0 0 0 rgba(0, 212, 170, 0); }
    }
    .corex-panel--flash {
        animation: corex-panel-flash 0.9s ease-out 1;
    }
    /* Per-shape halo radius — keeps the box-shadow ring shaped to the
       silhouette of each bucket's pin. */
    .corex-pin-circle.corex-pin--selected   { border-radius: 50%; }
    .corex-pin-square.corex-pin--selected   { border-radius: 3px; }
    .corex-pin-diamond.corex-pin--selected  { border-radius: 4px; }
    .corex-pin-hexagon.corex-pin--selected  { border-radius: 6px; }
    .corex-pin-triangle.corex-pin--selected { border-radius: 4px; }
    .corex-pin-house.corex-pin--selected    { border-radius: 4px 4px 50% 50%; }
    /* Scheme + composite legacy variants kept for any cached payload that
       still emits the older display_as. */
    .corex-pin.corex-pin-scheme.corex-pin--selected,
    .corex-pin.corex-pin-composite.corex-pin--selected {
        border-radius: 7px;
    }
    /* Cluster icon container — clear out the Leaflet.MarkerCluster default
       background/borders so our themed divIcon owns the look. */
    .corex-cluster {
        background: transparent !important;
        border: 0 !important;
    }
    /* Scheme + price labels at Z >= 16. Pointer-events:none so clicks pass
       through to the underlying pin (interactive:false on the marker is
       belt-and-braces). */
    .corex-scheme-label,
    .corex-price-label {
        background: transparent !important;
        border: 0 !important;
        pointer-events: none;
    }
</style>
@endpush

@section('corex-content')
<div id="corex-map-root" style="position: relative; height: calc(100vh - 64px); margin: -16px -20px -16px; display: flex; flex-direction: column; overflow: hidden; min-height: 0;">

    {{-- ── Header bar ────────────────────────────────────────────────────── --}}
    <div style="display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; background: var(--brand-default, #0b2a4a); border-bottom: 1px solid var(--border); flex-shrink: 0; z-index: 500;">
        <div style="display: flex; align-items: center; gap: 16px;">
            <h1 style="font-size: 1.25rem; font-weight: 700; color: #fff; margin: 0; line-height: 1.2;">CoreX Map</h1>
            <div id="map-loading-pill" style="display: none; padding: 4px 10px; font-size: 0.6875rem; font-weight: 500; background: var(--surface-2); color: var(--text-secondary); border-radius: 999px;">Loading pins…</div>
        </div>
        <div style="display: flex; align-items: center; gap: 8px;">
            {{-- Base-layer toggle --}}
            <div id="base-layer-toggle" style="display: inline-flex; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 2px;">
                <button data-base="streets" class="base-pill active" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: var(--brand-button); color: #fff; border: 0; border-radius: 4px; cursor: pointer;">Streets</button>
                <button data-base="satellite" class="base-pill" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">Satellite</button>
            </div>
            {{-- View-mode toggle.
                 POPIA owner-detail gate: the Agent View pill is only
                 rendered for users holding `access_prospecting` — the
                 same key that gates the MIC module (where owner PII
                 is otherwise visible). Users without the permission
                 see only the Seller pill; even if they hand-craft a
                 ?viewMode=agent request, the server-side
                 MapController::resolveViewMode helper enforces Seller.

                 NOTE: keep the block-form php directive below. The
                 inline single-line form pairs greedily with the next
                 closing directive further down the file (Laravel's
                 storePhpBlocks regex is non-greedy but blind to the
                 inline form), swallowing the intermediate left-rail
                 block as raw PHP and producing undefined $mapDefaultScope
                 / $mapIsOwner / $mapCanSeeAgentView at render time. --}}
            @php
                $mapCanSeeAgentView = (bool) (auth()->user()?->hasPermission('access_prospecting') ?? false);
            @endphp
            <div id="view-mode-toggle" data-can-see-agent="{{ $mapCanSeeAgentView ? '1' : '0' }}" style="display: inline-flex; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 2px;">
                @if($mapCanSeeAgentView)
                    <button data-mode="agent" class="mode-pill" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">Agent View</button>
                @endif
                <button data-mode="seller" class="mode-pill active" style="padding: 4px 10px; font-size: 0.75rem; font-weight: 500; background: var(--brand-button); color: #fff; border: 0; border-radius: 4px; cursor: pointer;">Seller View</button>
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

        {{-- Left rail. Phase A.3.1 — restructured into scope-first layout:
             stock-scope pills → compact layer icons → search → collapsible
             filter sections (<details>) → Apply / Clear. --}}
        <aside id="map-left-rail" style="width: 260px; flex-shrink: 0; background: var(--surface); border-right: 1px solid var(--border); padding: 12px; overflow-y: auto;">

            @php
                $mapUser    = auth()->user();
                $mapIsOwner = $mapUser?->isEffectiveOwner() ?? false;
                $mapDefaultScope = $mapIsOwner ? 'agency' : 'my';
            @endphp

            {{-- Phase A.3.2 — Saved searches dropdown + Save + Default star.
                 The dropdown is rendered as a native <select> for keyboard
                 + screen-reader ergonomics; the trailing buttons handle
                 Save (modal) and Set as default. --}}
            <div style="margin-bottom: 12px;">
                <div style="display: flex; align-items: center; gap: 6px; margin-bottom: 6px;">
                    <div style="flex: 1; font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600;">Saved searches</div>
                </div>
                <div style="display: flex; gap: 4px;">
                    <select id="saved-search-select" style="flex: 1; min-width: 0; padding: 5px 6px; border: 1px solid var(--border); border-radius: 4px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <option value="">— select —</option>
                    </select>
                    <button type="button" id="saved-search-default-btn" title="Set selected as default on load" aria-label="Set as default"
                            style="padding: 5px 8px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; color: var(--text-muted); cursor: pointer; font-size: 0.875rem;">★</button>
                    <button type="button" id="saved-search-delete-btn" title="Delete selected saved search" aria-label="Delete saved search"
                            style="padding: 5px 8px; background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; color: var(--text-muted); cursor: pointer; font-size: 0.875rem;">×</button>
                </div>
                <button type="button" id="saved-search-create-btn"
                        style="display: block; width: 100%; margin-top: 6px; padding: 5px 8px; background: transparent; border: 1px dashed var(--border); border-radius: 4px; color: var(--text-secondary); cursor: pointer; font-size: 0.6875rem;">
                    + Save current as new
                </button>
            </div>

            {{-- Phase A.3.1 — Stock Scope pills. Default 'my' for agents,
                 'agency' for owners; 'all' visible only for owners. --}}
            <div style="margin-bottom: 12px;">
                <div style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; margin-bottom: 6px;">Stock scope</div>
                <div id="scope-pills" data-default="{{ $mapDefaultScope }}" data-owner="{{ $mapIsOwner ? '1' : '0' }}"
                     style="display: inline-flex; width: 100%; background: var(--surface-2); border: 1px solid var(--border); border-radius: 6px; padding: 2px;">
                    <button type="button" data-scope="my"     class="scope-pill" style="flex: 1; padding: 5px 6px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">My</button>
                    <button type="button" data-scope="agency" class="scope-pill" style="flex: 1; padding: 5px 6px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">Agency</button>
                    @if($mapIsOwner)
                    <button type="button" data-scope="all"    class="scope-pill" style="flex: 1; padding: 5px 6px; font-size: 0.75rem; font-weight: 500; background: transparent; color: var(--text-secondary); border: 0; border-radius: 4px; cursor: pointer;">All</button>
                    @endif
                </div>
            </div>

            {{-- Phase A.3.1 — compact layer icon row. Each icon is a toggle;
                 hover shows the layer name + count. --}}
            <div style="margin-bottom: 12px;">
                <div style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; margin-bottom: 6px;">Layers</div>
                <div id="layer-list" style="display: flex; gap: 6px;">
                    @php
                        // Layer-chip palette MUST stay in sync with PIN_STYLES
                        // and LAYER_COLOURS in the JS section below. Otherwise
                        // the sidebar shows one colour while the map renders
                        // another — observed in fix-up #2 where the chip was
                        // still slate while the pin was purple, reinforcing
                        // the "M missing" misread.
                        $layerDefs = [
                            ['key' => 'hfc_listings',    'label' => 'HFC Listings',      'colour' => '#0b2a4a', 'letter' => 'H'],
                            ['key' => 'sold_comps',      'label' => 'Sold Comps',        'colour' => '#dc2626', 'letter' => 'S'],
                            ['key' => 'active_listings', 'label' => 'Portal Stock',      'colour' => '#f59e0b', 'letter' => 'P', 'title' => 'Portal Stock — competitor listings captured from Property24 and Private Property'],
                            ['key' => 'mic_subjects',    'label' => 'MIC Subjects',      'colour' => '#7c3aed', 'letter' => 'M'],
                            ['key' => 'scheme_owners',   'label' => 'Sectional Schemes', 'colour' => '#06b6d4', 'letter' => 'O', 'sensitive' => true],
                            ['key' => 'tracked_properties', 'label' => 'Tracked',        'colour' => '#00d4aa', 'letter' => 'T', 'sensitive' => true, 'title' => 'Tracked — prospecting candidates with geocoded GPS, not yet on agency stock (Agent View only)'],
                        ];
                    @endphp
                    @foreach($layerDefs as $l)
                    {{-- Fix-up #3 §3 — left-rail layer chip uses the SAME pin shape +
                         colour + glyph as the map pin for its bucket. JS injects the
                         SVG into [data-layer-pin-icon] on init; the button is a
                         transparent box-shaped container so the SVG silhouette is
                         the only visual. Hover/active border + count badge below
                         continue to work unchanged. --}}
                    <button type="button" data-layer-toggle="{{ $l['key'] }}" data-on="1"
                            @if($l['sensitive'] ?? false) data-sensitive="1" @endif
                            title="{{ $l['title'] ?? $l['label'] }}"
                            aria-label="Toggle {{ $l['label'] }}"
                            style="position: relative; display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; background: transparent; color: #fff; border: 2px solid transparent; border-radius: 4px; cursor: pointer; padding: 0;">
                        <span data-layer-pin-icon="{{ $l['key'] }}" style="display:inline-flex;align-items:center;justify-content:center;width:100%;height:100%;"></span>
                        <span data-layer-count="{{ $l['key'] }}"
                              style="position: absolute; bottom: -4px; right: -4px; min-width: 14px; padding: 1px 3px; background: var(--surface); color: var(--text-primary); border: 1px solid var(--border); border-radius: 8px; font-size: 0.5625rem; font-weight: 600; line-height: 1; font-variant-numeric: tabular-nums;">—</span>
                    </button>
                    @endforeach
                </div>
                <div id="layer-cap-notice" style="display: none; margin-top: 8px; padding: 6px 8px; font-size: 0.625rem; color: var(--ds-amber, #d97706); background: color-mix(in srgb, var(--ds-amber, #d97706) 8%, transparent); border-radius: 4px;"></div>
            </div>

            {{-- Phase A.3.1 — search input. 500ms debounce in JS. --}}
            <div style="margin-bottom: 12px;">
                <input type="text" id="filter-search" placeholder="Search address / scheme / agent…" autocomplete="off"
                       style="width: 100%; padding: 7px 10px; border: 1px solid var(--border); border-radius: 6px; background: var(--surface-2); color: var(--text-primary); font-size: 0.8125rem; box-sizing: border-box;">
            </div>

            {{-- Phase 3g V2 Part A — display mode radio. --}}
            <div style="margin-bottom: 12px;">
                <div style="font-size: 0.6875rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); font-weight: 600; margin-bottom: 6px;">Display mode</div>
                <div id="display-mode-group" style="display: flex; gap: 8px;">
                    @foreach(['pins' => 'Pins', 'heatmap' => 'Heat', 'both' => 'Both'] as $key => $label)
                    <label style="display: inline-flex; align-items: center; gap: 5px; cursor: pointer; font-size: 0.75rem;">
                        <input type="radio" name="display-mode" value="{{ $key }}" {{ $key === 'pins' ? 'checked' : '' }} style="margin: 0;">
                        <span style="color: var(--text-primary);">{{ $label }}</span>
                    </label>
                    @endforeach
                </div>
            </div>

            {{-- Phase A.3.1 — collapsible filter sections via native <details>. --}}
            <div id="filters-body" style="margin-bottom: 12px; padding-top: 12px; border-top: 1px solid var(--border);">

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Property type</summary>
                    <div style="display: flex; flex-direction: column; gap: 3px; padding: 6px 4px 8px; font-size: 0.75rem;">
                        @foreach(['house' => 'House', 'sectional' => 'Apartment / Sectional', 'townhouse' => 'Townhouse', 'vacant' => 'Vacant Land'] as $val => $label)
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" data-filter-type="{{ $val }}" checked style="margin: 0;">
                            <span style="color: var(--text-primary);">{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Bedrooms</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-bedrooms-min" min="0" max="20" placeholder="min" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">–</span>
                        <input type="number" id="filter-bedrooms-max" min="0" max="20" placeholder="max" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Bathrooms</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-bathrooms-min" min="0" max="20" placeholder="min" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">–</span>
                        <input type="number" id="filter-bathrooms-max" min="0" max="20" placeholder="max" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Price (R)</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-price-min" min="0" max="100000000" step="100000" placeholder="min" style="width: 90px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">–</span>
                        <input type="number" id="filter-price-max" min="0" max="100000000" step="100000" placeholder="max" style="width: 90px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Stand size (m²)</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-stand-min" min="0" max="1000000" step="50" placeholder="min" style="width: 80px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">–</span>
                        <input type="number" id="filter-stand-max" min="0" max="1000000" step="50" placeholder="max" style="width: 80px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Building size (m²)</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-building-min" min="0" max="100000" step="10" placeholder="min" style="width: 80px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">–</span>
                        <input type="number" id="filter-building-max" min="0" max="100000" step="10" placeholder="max" style="width: 80px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Listing status</summary>
                    <div style="display: flex; flex-direction: column; gap: 3px; padding: 6px 4px 8px; font-size: 0.75rem;">
                        @foreach(['active' => 'Active', 'sold' => 'Sold', 'draft' => 'Draft'] as $val => $label)
                        <label style="display: flex; align-items: center; gap: 6px; cursor: pointer;">
                            <input type="checkbox" data-filter-status="{{ $val }}" style="margin: 0;">
                            <span style="color: var(--text-primary);">{{ $label }}</span>
                        </label>
                        @endforeach
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Sold date window</summary>
                    <div style="padding: 6px 4px 8px; font-size: 0.75rem;">
                        <select id="filter-sold-window" style="width: 100%; padding: 4px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                            <option value="">All sold</option>
                            <option value="3mo">Last 3 months</option>
                            <option value="6mo">Last 6 months</option>
                            <option value="12mo">Last 12 months</option>
                            <option value="24mo">Last 24 months</option>
                        </select>
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Days on market</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-dom-min" min="0" max="10000" placeholder="min" style="width: 70px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">–</span>
                        <input type="number" id="filter-dom-max" min="0" max="10000" placeholder="max" style="width: 70px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <details class="map-filter-block" style="margin-bottom: 4px;">
                    <summary style="cursor: pointer; padding: 5px 0; font-size: 0.75rem; font-weight: 600; color: var(--text-secondary);">Date range (year)</summary>
                    <div style="display: flex; gap: 6px; align-items: center; padding: 6px 4px 8px; font-size: 0.75rem;">
                        <input type="number" id="filter-year-from" min="2018" max="2030" placeholder="{{ now()->year - 5 }}" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                        <span style="color: var(--text-muted);">to</span>
                        <input type="number" id="filter-year-to"   min="2018" max="2030" placeholder="{{ now()->year }}" style="width: 60px; padding: 3px 6px; border: 1px solid var(--border); border-radius: 3px; background: var(--surface-2); color: var(--text-primary); font-size: 0.75rem;">
                    </div>
                </details>

                <div style="display: flex; gap: 6px; margin-top: 10px;">
                    <button type="button" id="filter-apply"
                        style="flex: 1; padding: 7px 10px; font-size: 0.75rem; font-weight: 600; color: #fff; background: var(--brand-button, #0ea5e9); border: 0; border-radius: 4px; cursor: pointer;">
                        Apply
                    </button>
                    <button type="button" id="filter-clear"
                        style="flex: 1; padding: 7px 10px; font-size: 0.75rem; font-weight: 500; color: var(--text-secondary); background: var(--surface-2); border: 1px solid var(--border); border-radius: 4px; cursor: pointer;">
                        Clear all
                    </button>
                </div>
                <div id="filters-count-strip" style="display: none; margin-top: 8px; padding: 4px 8px; font-size: 0.6875rem; color: var(--text-muted); background: var(--surface-2); border-radius: 4px; text-align: center;"></div>
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
        <main style="flex: 1; position: relative; background: var(--surface-2, #e5e7eb);">
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
    // Phase A.3.2 — saved-search CRUD endpoints.
    const SAVED_SEARCH_INDEX_URL  = @json(route('corex.map.saved-searches.index'));
    const SAVED_SEARCH_STORE_URL  = @json(route('corex.map.saved-searches.store'));
    const SAVED_SEARCH_UPDATE_TPL = @json(route('corex.map.saved-searches.update',  ['id' => '__ID__']));
    const SAVED_SEARCH_DEL_TPL    = @json(route('corex.map.saved-searches.destroy', ['id' => '__ID__']));
    const CSRF_TOKEN = document.querySelector('meta[name=csrf-token]')?.content || '';
    const PROPERTY_SHOW_URL_TPL  = @json(route('corex.properties.show', ['property' => '__ID__']));
    const PROPERTY_OUTREACH_TPL  = @json(route('seller-outreach.entry.from-property', ['property' => '__ID__']));
    const MIC_REPORT_SHOW_TPL    = @json(route('market-intelligence.reports.show', ['report' => '__ID__']));
    const MIC_OPPORTUNITIES_URL  = @json(route('market-intelligence.opportunities'));
    // Phase B Fix 2+3 — T-pin "WhatsApp / Pitch" entry point (mirrors fromProspecting).
    const TP_OUTREACH_TPL        = @json(route('seller-outreach.entry.from-tracked-property', ['trackedProperty' => '__ID__']));

    // Multi-tenant agency context for the H pin's logo render — spec §4 of
    // map-visual-identity-spec.md. Resolved server-side from the viewing
    // user's effectiveAgency; the H pin renders the VIEWER's brand on its
    // own stock, never a hard-coded letter or hard-coded HFC.
    const AGENCY_LOGO_URL  = @json($agency?->logo_path ? asset('storage/' . $agency->logo_path) : null);
    const AGENCY_INITIALS  = @json($agency?->initials ?? '');
    const AGENCY_NAME      = @json($agency?->name ?? 'Agency');

    // Single-record category visuals. Aligned to the high-contrast palette
    // ruling from the visual identity fix-ups — six strongly distinct hues
    // spread across the spectrum (no two adjacent buckets within 60° of hue,
    // colour-blind safe at marker size). Final hexes:
    //   COMPANY (H)        navy    #0b2a4a
    //   PORTAL  (P)        amber   #f59e0b
    //   TRACKED (T)        teal    #00d4aa  (the spine)
    //   CMA-M (subject)    purple  #7c3aed
    //   CMA-O (owner)      cyan    #06b6d4  (fix-up #3 §2 — was magenta,
    //                                       read as nearly-red against the
    //                                       map and conflicted with S-red)
    //   CMA-S (sold)       red     #dc2626
    const LAYER_COLOURS = {
        hfc_listings:       '#0b2a4a',
        sold_comps:         '#dc2626',
        active_listings:    '#f59e0b',
        mic_subjects:       '#7c3aed',
        scheme_owners:      '#06b6d4',
        tracked_properties: '#00d4aa',
    };
    const LAYER_LETTERS = {
        hfc_listings: 'H', sold_comps: 'S', active_listings: 'P',
        mic_subjects: 'M', scheme_owners: 'O', tracked_properties: 'T',
    };
    const LAYER_NAMES = {
        hfc_listings: 'HFC Listing',
        sold_comps: 'Sold Comp',
        active_listings: 'Portal Stock',
        mic_subjects: 'MIC Subject',
        scheme_owners: 'Sectional Scheme',
        tracked_properties: 'Tracked',
    };

    // Composite pin palette — neutral slate so it reads as "multiple sources here"
    // and never collides with any single-category colour.
    // RETAINED for any legacy code path; new visual identity drives composites
    // via primary-shape + corner-dot badges (spec §2).
    const COMPOSITE_BG     = '#334155';
    const COMPOSITE_BORDER = '#00d4aa';

    // ════════════════════════════════════════════════════════════════════
    // VISUAL IDENTITY MODULE
    // Spec: .ai/specs/map-visual-identity-spec.md (Johan-ruled).
    // Single source of truth for pin shape + palette + glyph + cluster theme.
    // Client-side only — server payload shape unchanged.
    // ════════════════════════════════════════════════════════════════════

    // Category → bucket. Drives cluster theming, badge dot colour, and the
    // shape lookup in PIN_STYLES.
    const BUCKET_OF = {
        hfc_listings:       'COMPANY',
        active_listings:    'PORTAL',
        tracked_properties: 'TRACKED',
        sold_comps:         'CMA',
        mic_subjects:       'CMA',
        scheme_owners:      'CMA',
    };

    // Per-pin visual spec. pinKey is bucket-derived ('H'/'P'/'T'/'M'/'O') for
    // single-shape buckets; S splits by source_class:
    //   S_market = red circle with white "S" glyph (no slash, no ring).
    //   S_own    = SAME shape as H pin (house + agency logo) PLUS a red
    //              "S" corner badge top-left — logo stays fully visible,
    //              the corner badge is the single-glance "ours" signal.
    // M square bumped 18→22px (was visually invisible against the dark
    // satellite tiles); same shape, just bigger + higher-contrast purple.
    const PIN_STYLES = {
        H:        { shape: 'house',    size: [24, 28], fill: '#0b2a4a', stroke: '#ffffff', strokeWidth: 2 },
        P:        { shape: 'diamond',  size: [22, 22], fill: '#f59e0b', stroke: '#ffffff', strokeWidth: 2, glyph: 'P', glyphFill: '#0b2a4a' },
        T:        { shape: 'hexagon',  size: [22, 22], fill: '#00d4aa', stroke: '#0b2a4a', strokeWidth: 2 },
        M:        { shape: 'square',   size: [22, 22], fill: '#7c3aed', stroke: '#ffffff',          strokeWidth: 2, glyph: 'M', glyphFill: '#ffffff' },
        O:        { shape: 'triangle', size: [24, 22], fill: '#06b6d4', stroke: '#ffffff', strokeWidth: 2, glyph: 'O', glyphFill: '#0b2a4a' },
        S_market: { shape: 'circle',   size: [22, 22], fill: '#dc2626', stroke: '#ffffff', strokeWidth: 2, glyph: 'S', glyphFill: '#ffffff' },
        // S_own is rendered via the house-pin path, NOT via shapeSvg —
        // the entry stays so pinKeyForRecord can dispatch on it, but the
        // primary geometry is reused from H. The red-S corner badge is
        // added by the locationIcon caller, not from here.
        S_own:    { shape: 'house',    size: [24, 28], fill: '#0b2a4a', stroke: '#ffffff', strokeWidth: 2, ownSoldBadge: true },
    };

    // Bucket → cluster palette. CMA uses purple (the M colour) since the
    // CMA bucket is dominated by M/O records; slate (the previous CMA
    // cluster colour) was the source of the "M missing" complaint —
    // unreadable against satellite tiles.
    const CLUSTER_THEMES = {
        COMPANY: { fill: '#0b2a4a', ring: '#ffffff',            text: '#ffffff' },
        PORTAL:  { fill: '#f59e0b', ring: '#ffffff',            text: '#0b2a4a' },
        TRACKED: { fill: '#00d4aa', ring: '#0b2a4a',            text: '#0b2a4a' },
        CMA:     { fill: '#7c3aed', ring: '#ffffff',            text: '#ffffff' },
        MIXED:   { fill: '#0b2a4a', ring: '#00d4aa',            text: '#00d4aa' },
    };

    // Composite-badge priority — TRACKED always wins top-right; the rest
    // fill remaining slots in this order. Max 3 badges; overflow rolls
    // into a "+N" pill on the top-left.
    const BADGE_PRIORITY = ['T', 'P', 'S', 'M', 'O'];

    function pinKeyForRecord(rec) {
        if (!rec) return null;
        if (rec.category === 'sold_comps') {
            // Robustness: missing source_class defaults to 'market' (the
            // safe interpretation — never claim "ours" without evidence).
            return (rec.source_class === 'own') ? 'S_own' : 'S_market';
        }
        return ({ hfc_listings: 'H', active_listings: 'P', mic_subjects: 'M',
                  scheme_owners: 'O', tracked_properties: 'T' })[rec.category] || null;
    }

    function bucketForRecord(rec) {
        return rec ? (BUCKET_OF[rec.category] || 'CMA') : 'CMA';
    }

    function bucketForLocation(loc) {
        if (loc.display_as === 'scheme') return 'CMA';
        return bucketForRecord((loc.records || [])[0]);
    }

    // ── SVG shape generators ────────────────────────────────────────────
    // Each returns an inline SVG sized to its style.size box. Drop-shadow
    // filter is applied via wrapper so it lifts off the map tiles without
    // adding a second DOM layer.

    function svgWrap(size, body) {
        const w = size[0], h = size[1];
        return '<svg xmlns="http://www.w3.org/2000/svg" width="' + w + '" height="' + h
            + '" viewBox="0 0 ' + w + ' ' + h
            + '" style="display:block;filter:drop-shadow(0 1px 2px rgba(0,0,0,.45));">'
            + body + '</svg>';
    }

    function shapeSvg(style) {
        const w = style.size[0], h = style.size[1];
        const fill = style.fill, stroke = style.stroke, sw = style.strokeWidth || 2;
        let body = '';
        switch (style.shape) {
            case 'circle': {
                const r = (Math.min(w, h) - sw) / 2;
                body = '<circle cx="' + (w/2) + '" cy="' + (h/2) + '" r="' + r
                    + '" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '"/>';
                break;
            }
            case 'square': {
                body = '<rect x="' + (sw/2) + '" y="' + (sw/2)
                    + '" width="' + (w - sw) + '" height="' + (h - sw)
                    + '" rx="2" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '"/>';
                break;
            }
            case 'diamond': {
                const cx = w/2, cy = h/2;
                const r  = (Math.min(w, h) - sw) / 2;
                body = '<polygon points="' + cx + ',' + (cy - r) + ' ' + (cx + r) + ',' + cy
                    + ' ' + cx + ',' + (cy + r) + ' ' + (cx - r) + ',' + cy
                    + '" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '" stroke-linejoin="miter"/>';
                break;
            }
            case 'hexagon': {
                const cx = w/2, cy = h/2;
                const r  = Math.min(w, h) / 2 - sw / 2;
                const pts = [];
                for (let i = 0; i < 6; i++) {
                    const a = (Math.PI / 3) * i - Math.PI / 2;
                    pts.push((cx + r * Math.cos(a)).toFixed(2) + ',' + (cy + r * Math.sin(a)).toFixed(2));
                }
                body = '<polygon points="' + pts.join(' ')
                    + '" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '" stroke-linejoin="miter"/>';
                break;
            }
            case 'triangle': {
                // Pointy-top isoceles, sized to fit the bbox.
                const cx = w/2;
                body = '<polygon points="' + cx + ',' + sw + ' ' + (w - sw) + ',' + (h - sw)
                    + ' ' + sw + ',' + (h - sw)
                    + '" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '" stroke-linejoin="round"/>';
                break;
            }
            case 'house': {
                // House-pin: rounded rectangle body with a roof triangle on top.
                // Designed at 24×28: roof apex at y=1, eaves at y=5, body
                // 22w × 21h centred, base flat (the pin's bottom edge).
                const roof = 'M ' + (w/2) + ' 1 L ' + (w - 1) + ' 5 L 1 5 Z';
                const bod  = '<rect x="1" y="5" width="' + (w - 2) + '" height="' + (h - 6)
                    + '" rx="3" fill="' + fill + '" stroke="' + stroke + '" stroke-width="' + sw + '"/>';
                body = '<path d="' + roof + '" fill="' + fill + '" stroke="' + stroke
                    + '" stroke-width="' + sw + '" stroke-linejoin="round"/>' + bod;
                break;
            }
        }
        return svgWrap(style.size, body);
    }

    function glyphLayer(style) {
        if (!style.glyph) return '';
        const yShift = style.shape === 'triangle' ? 2 : 0;
        return '<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;'
            + 'font-family:Plus Jakarta Sans,sans-serif;font-size:11px;font-weight:700;color:'
            + (style.glyphFill || '#ffffff') + ';pointer-events:none;transform:translateY('
            + yShift + 'px);">' + style.glyph + '</div>';
    }

    // ── H pin inner: agency logo → initials → outline house (spec §4) ───
    // Logo bumped from 16×16 → 19×19 (~20% larger per fix-up §3). Centred
    // inside the 24×28 house body (body is x=1, y=5, w=22, h=21), so the
    // 19×19 logo sits at left=2.5, top=7 and stays inside the outline.

    function housePinInner() {
        if (AGENCY_LOGO_URL) {
            return '<img src="' + escapeAttr(AGENCY_LOGO_URL) + '" alt="' + escapeAttr(AGENCY_NAME) + '" '
                + 'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';" '
                + 'style="position:absolute;left:2.5px;top:7px;width:19px;height:19px;border-radius:50%;'
                + 'object-fit:cover;background:#ffffff;"/>'
                + houseInitialsFallback(true);
        }
        if (AGENCY_INITIALS) return houseInitialsFallback(false);
        return houseOutlineFallback();
    }

    function houseInitialsFallback(hiddenByDefault) {
        const fs = AGENCY_INITIALS && AGENCY_INITIALS.length >= 3 ? 9 : 11;
        const display = hiddenByDefault ? 'none' : 'flex';
        return '<div style="position:absolute;left:2.5px;top:7px;width:19px;height:19px;display:' + display + ';'
            + 'align-items:center;justify-content:center;border-radius:50%;background:rgba(255,255,255,0.18);'
            + 'color:#ffffff;font-family:Plus Jakarta Sans,sans-serif;font-size:' + fs + 'px;font-weight:700;'
            + 'letter-spacing:-0.5px;">' + escapeHtml(AGENCY_INITIALS || '') + '</div>';
    }

    function houseOutlineFallback() {
        return '<svg style="position:absolute;left:3.5px;top:8px;" width="17" height="17" viewBox="0 0 24 24" '
            + 'fill="none" stroke="#ffffff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
            + '<polyline points="9 22 9 12 15 12 15 22"/></svg>';
    }

    /** Red "S" corner badge for own-sold pins. Placed top-left as a
     *  10×10 red pill with a white "S" — matches the corner-badge
     *  language used elsewhere (count badges, tracked badges in legacy)
     *  but in CoreX-red so it reads as the sold mark instantly. */
    function ownSoldCornerBadge() {
        return '<span style="position:absolute;top:-3px;left:-3px;width:11px;height:11px;'
            + 'background:#dc2626;color:#ffffff;border-radius:50%;border:1.5px solid #ffffff;'
            + 'font-family:Plus Jakarta Sans,sans-serif;font-size:8px;font-weight:700;line-height:1;'
            + 'display:flex;align-items:center;justify-content:center;'
            + 'box-shadow:0 1px 2px rgba(0,0,0,.5);">S</span>';
    }

    // ── Composite rings (fix-up §4) ─────────────────────────────────────
    // Replaces the corner-badge system entirely. Each additional bucket
    // present at the location renders as a 2px concentric ring around the
    // primary pin, with a 1px gap between rings. Stacked OUTWARD from the
    // pin in priority order T > P > S > M > O — T closest to the pin if
    // present. Cap at 3 rings + a small "+N" pill on the top-left for the
    // overflow. The Tracked spine badge is DROPPED (ring replaces badge —
    // no doubling up).

    /** Resolve the ordered list of ring-bucket-keys for a composite
     *  location. Returns ['T', 'P', ...] in stack order, innermost first. */
    function compositeRingBuckets(loc) {
        const records = loc.records || [];
        const primary = records[0] || {};
        const primaryKey = pinKeyForRecord(primary);
        const primarySlot = (primaryKey || '').charAt(0);

        const seen = new Set();
        records.slice(1).forEach(rec => {
            const k = pinKeyForRecord(rec);
            if (!k) return;
            const slotKey = k.charAt(0);
            if (slotKey === primarySlot) return;
            seen.add(slotKey);
        });
        // Tracked spine override — when LocationGrouper flagged
        // has_tracked_record and the primary isn't T, T is mandatory.
        if (loc.has_tracked_record && primaryKey !== 'T') seen.add('T');

        return BADGE_PRIORITY.filter(k => seen.has(k));
    }

    /** Total radial extent (in px) the ring system adds beyond the pin's
     *  bounding box. Used by the outer wrapper sizing so the rings aren't
     *  clipped by Leaflet's iconSize.
     *    Each ring contributes 2px ring + 1px gap = 3px radial. Plus a
     *    1px leading gap from the pin edge to the first ring.
     *    Cap at 3 rings so the wrapper never blows up. */
    function ringRadialExtent(ringCount) {
        const visible = Math.min(ringCount, 3);
        return visible === 0 ? 0 : (1 + visible * 3);
    }

    function ringColourFor(k) {
        // Keep in sync with LAYER_COLOURS — single source for bucket
        // accent colours used in composite rings and sidebar icons.
        return ({ T: '#00d4aa', P: '#f59e0b', S: '#dc2626', M: '#7c3aed', O: '#06b6d4' })[k] || '#94a3b8';
    }

    /** Map a category key (database value) to a pinKey lookup in
     *  PIN_STYLES. Used by sidebar icon rendering so the same bucket
     *  reuses the map pin's shape + colour at a smaller size. */
    function pinKeyForCategory(cat) {
        return ({
            hfc_listings:       'H',
            active_listings:    'P',
            tracked_properties: 'T',
            mic_subjects:       'M',
            scheme_owners:      'O',
            sold_comps:         'S_market',
        })[cat] || null;
    }

    /** Fix-up #3 §3 / #4 §2 — render a miniature pin icon for use in
     *  sidebar composite-list section headers AND left-rail layer chips.
     *  Reuses shapeSvg + glyphLayer from the map pin module so the sidebar
     *  bucket icon is the SAME shape + colour + (where applicable) glyph
     *  + (for H / S_own) the SAME agency logo as the map pin.
     *
     *  Sized down to `sizePx` px; default 18 to match the previous chip
     *  footprint. Returns inline-block HTML, ready to drop into a row. */
    function sidebarBucketIcon(category, sizePx) {
        const px = sizePx || 18;
        const pinKey = pinKeyForCategory(category);
        if (!pinKey) {
            return '<span style="display:inline-block;width:' + px + 'px;height:' + px
                + 'px;background:#94a3b8;border-radius:50%;"></span>';
        }
        const baseStyle = PIN_STYLES[pinKey];
        if (!baseStyle) {
            return '<span style="display:inline-block;width:' + px + 'px;height:' + px
                + 'px;background:#94a3b8;border-radius:50%;"></span>';
        }
        // Scale to the target size, preserving aspect ratio. House pin is
        // taller than wide; everything else is square or near-square.
        const w0 = baseStyle.size[0], h0 = baseStyle.size[1];
        const longest = Math.max(w0, h0);
        const scale = px / longest;
        const W = Math.max(8, Math.round(w0 * scale));
        const H = Math.max(8, Math.round(h0 * scale));
        const style = {
            shape: baseStyle.shape,
            size: [W, H],
            fill: baseStyle.fill,
            stroke: baseStyle.stroke,
            strokeWidth: Math.max(1, Math.round((baseStyle.strokeWidth || 2) * scale)),
            glyph: baseStyle.glyph,
            glyphFill: baseStyle.glyphFill,
        };

        // Fix-up #4 §2 — H + S_own MUST carry the agency logo so the sidebar
        // chip matches the map pin's visual signature. miniHousePinInner()
        // scales housePinInner's logo/initials/Lucide-fallback chain to the
        // requested icon size, so an 18px or 24px sidebar chip shows the
        // same agency mark as the 24×28 map pin.
        const inner = (pinKey === 'H' || pinKey === 'S_own')
            ? miniHousePinInner(style.size)
            : (style.glyph ? glyphLayer(style) : '');

        // Own-sold sidebar chip also gets the red "S" corner badge so it
        // reads "ours" at a glance, identical to the map pin.
        const cornerBadge = (pinKey === 'S_own') ? ownSoldCornerBadge() : '';

        return '<span style="position:relative;display:inline-flex;width:' + W + 'px;height:' + H
            + 'px;vertical-align:middle;flex-shrink:0;">'
            + shapeSvg(style)
            + inner
            + cornerBadge
            + '</span>';
    }

    /** Scaled-down housePinInner for sidebar chips. House map pin places
     *  a 19×19 logo at (left:2.5, top:7) inside a 24×28 body — relative
     *  positions: 10.4%, 25%, 79% wide, 67.9% tall. Re-derive at the
     *  sidebar size so the logo follows the same proportions regardless
     *  of chip size. Falls through the same logo → initials → Lucide
     *  outline chain as the map pin. */
    function miniHousePinInner(size) {
        const w = size[0], h = size[1];
        const L = Math.max(1, Math.round(w * 0.104));
        const T = Math.max(2, Math.round(h * 0.25));
        const W = Math.max(6, Math.round(w * 0.79));
        const H = Math.max(6, Math.round(h * 0.679));
        if (AGENCY_LOGO_URL) {
            return '<img src="' + escapeAttr(AGENCY_LOGO_URL) + '" alt="' + escapeAttr(AGENCY_NAME) + '" '
                + 'onerror="this.style.display=\'none\';this.nextElementSibling.style.display=\'flex\';" '
                + 'style="position:absolute;left:' + L + 'px;top:' + T + 'px;width:' + W
                + 'px;height:' + H + 'px;border-radius:50%;object-fit:cover;background:#ffffff;"/>'
                + miniHouseInitials(L, T, W, H, true);
        }
        if (AGENCY_INITIALS) return miniHouseInitials(L, T, W, H, false);
        return miniHouseOutline(L, T, W, H);
    }

    function miniHouseInitials(L, T, W, H, hiddenByDefault) {
        const fs = Math.max(6, Math.round(H * 0.6));
        const display = hiddenByDefault ? 'none' : 'flex';
        return '<div style="position:absolute;left:' + L + 'px;top:' + T + 'px;width:' + W
            + 'px;height:' + H + 'px;display:' + display + ';align-items:center;justify-content:center;'
            + 'border-radius:50%;background:rgba(255,255,255,0.18);color:#ffffff;'
            + 'font-family:Plus Jakarta Sans,sans-serif;font-size:' + fs + 'px;font-weight:700;'
            + 'letter-spacing:-0.5px;">' + escapeHtml(AGENCY_INITIALS || '') + '</div>';
    }

    function miniHouseOutline(L, T, W, H) {
        return '<svg style="position:absolute;left:' + L + 'px;top:' + T + 'px;" '
            + 'width="' + W + '" height="' + H + '" viewBox="0 0 24 24" '
            + 'fill="none" stroke="#ffffff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">'
            + '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>'
            + '<polyline points="9 22 9 12 15 12 15 22"/></svg>';
    }

    /** SVG ring layer — concentric circles centred on the pin, sized to
     *  enclose the pin geometry. Caller positions this at the centre of
     *  the wrapper; wrapper size = pin size + 2 * ringRadialExtent. */
    function compositeRingsSvg(loc, pinSize) {
        const buckets = compositeRingBuckets(loc);
        if (buckets.length === 0) return '';
        const visible = buckets.slice(0, 3);
        const extent  = ringRadialExtent(visible.length);
        const W = pinSize[0] + 2 * extent;
        const H = pinSize[1] + 2 * extent;
        const cx = W / 2, cy = H / 2;
        // Innermost ring sits 1px outside the pin's enclosing circle.
        const pinR = Math.max(pinSize[0], pinSize[1]) / 2;
        let r = pinR + 1;
        const rings = visible.map(k => {
            r += 1; // 2px ring rendered with stroke-width 2, so centre is +1
            const ring = '<circle cx="' + cx + '" cy="' + cy + '" r="' + r
                + '" fill="none" stroke="' + ringColourFor(k)
                + '" stroke-width="2" />';
            r += 2; // step past the ring (centre + 1 for the other half) + 1px gap
            return ring;
        }).join('');
        return '<svg style="position:absolute;left:0;top:0;pointer-events:none;" '
             + 'width="' + W + '" height="' + H + '" viewBox="0 0 ' + W + ' ' + H + '">'
             + rings + '</svg>';
    }

    /** "+N" overflow pill placed top-left of the wrapper when more than
     *  3 ring-buckets are present. Mirrors the count-badge styling so the
     *  agent reads it as a numeric. */
    function ringOverflowPill(loc) {
        const buckets = compositeRingBuckets(loc);
        const overflow = Math.max(0, buckets.length - 3);
        if (overflow === 0) return '';
        return '<span style="position:absolute;top:-6px;left:-8px;background:#0b2a4a;color:#ffffff;'
            + 'font-family:Plus Jakarta Sans,sans-serif;font-size:9px;font-weight:700;border-radius:10px;'
            + 'padding:1px 4px;line-height:1;box-shadow:0 1px 2px rgba(0,0,0,.4);white-space:nowrap;'
            + 'border:1px solid #ffffff;">+' + overflow + '</span>';
    }

    function countBadge(count, accent) {
        return '<span style="position:absolute;top:-6px;right:-8px;background:#ffffff;color:#0f172a;'
            + 'font-family:Plus Jakarta Sans,sans-serif;font-size:10px;font-weight:700;border-radius:10px;'
            + 'padding:1px 5px;line-height:1;box-shadow:0 1px 2px rgba(0,0,0,.4);white-space:nowrap;'
            + 'border:1px solid ' + accent + ';">' + count + '</span>';
    }

    // ── Themed cluster icon (spec §5) ───────────────────────────────────

    function clusterIcon(cluster) {
        const counts = { COMPANY: 0, PORTAL: 0, CMA: 0, TRACKED: 0 };
        let total = 0;
        cluster.getAllChildMarkers().forEach(m => {
            const b = (m.options && m.options.bucket) || 'CMA';
            counts[b] = (counts[b] || 0) + 1;
            total++;
        });
        const sorted = Object.entries(counts).filter(([, n]) => n > 0).sort((a, b) => b[1] - a[1]);
        let dominant = sorted.length ? sorted[0][0] : 'CMA';
        // MIXED when the runner-up is within 20% of the leader.
        if (sorted.length > 1 && sorted[1][1] > 0
            && (sorted[0][1] - sorted[1][1]) / sorted[0][1] < 0.2) {
            dominant = 'MIXED';
        }
        const theme = CLUSTER_THEMES[dominant] || CLUSTER_THEMES.CMA;
        const size  = total < 10 ? 36 : (total < 100 ? 44 : 52);
        const fs    = total < 10 ? 12 : (total < 100 ? 13 : 14);
        const rw    = dominant === 'MIXED' ? 3 : 2;

        const html = '<div style="display:flex;align-items:center;justify-content:center;'
            + 'width:' + size + 'px;height:' + size + 'px;border-radius:50%;'
            + 'background:' + theme.fill + ';color:' + theme.text + ';'
            + 'border:' + rw + 'px solid ' + theme.ring + ';'
            + 'font-family:Plus Jakarta Sans,sans-serif;font-size:' + fs + 'px;font-weight:700;'
            + 'box-shadow:0 1px 4px rgba(0,0,0,.5);">' + total + '</div>';
        return L.divIcon({
            html,
            className: 'corex-cluster corex-cluster-' + dominant.toLowerCase(),
            iconSize: [size, size],
        });
    }

    // ── Scheme + price labels at Z ≥ N₂ (spec §3) ───────────────────────

    const LABEL_ZOOM_THRESHOLD = 16;
    let   schemeLabelLayer = L.layerGroup();
    let   priceLabelLayer  = L.layerGroup();

    function buildLabelIcon(html, offsetYPercent) {
        // iconSize=[0,0] + transform places the label freely above/below the
        // marker without taking up Leaflet-managed layout space. Anchor at
        // the latlng centre, then translate by content size.
        return L.divIcon({
            html: '<div style="position:absolute;left:0;top:0;transform:translate(-50%,'
                + offsetYPercent + ');">' + html + '</div>',
            className: '',
            iconSize:   [0, 0],
            iconAnchor: [0, 0],
        });
    }

    function schemeLabelHtml(name, count) {
        return '<span style="display:inline-block;background:#0b2a4a;color:#ffffff;padding:3px 7px;'
            + 'border-radius:3px;font-family:Plus Jakarta Sans,sans-serif;font-size:11px;font-weight:600;'
            + 'white-space:nowrap;box-shadow:0 1px 3px rgba(0,0,0,.45);border:1px solid rgba(255,255,255,0.1);">'
            + escapeHtml(name) + ' (' + count + ')</span>';
    }

    function priceLabelHtml(prefix, value) {
        return '<span style="display:inline-block;background:rgba(15,23,42,0.88);color:#ffffff;padding:1px 5px;'
            + 'border-radius:2px;font-family:Plus Jakarta Sans,sans-serif;font-size:10px;font-weight:600;'
            + 'white-space:nowrap;border:1px solid rgba(255,255,255,0.1);">' + prefix + value + '</span>';
    }

    function formatPriceShort(v) {
        if (!v) return '';
        if (v >= 1000000) return (v / 1000000).toFixed(1).replace(/\.0$/, '') + 'M';
        if (v >= 1000)    return (v / 1000).toFixed(0) + 'k';
        return String(v);
    }

    function formatPriceLong(v) {
        if (!v) return '';
        return (Math.round(v)).toLocaleString('en-ZA');
    }

    function rebuildLabelLayers(payload) {
        schemeLabelLayer.clearLayers();
        priceLabelLayer.clearLayers();
        if (!payload || !Array.isArray(payload.locations)) return;

        payload.locations.forEach(loc => {
            if (loc.display_as === 'scheme' && loc.scheme_name) {
                const label = schemeLabelHtml(loc.scheme_name, loc.record_count);
                const icon  = buildLabelIcon(label, '-160%');
                schemeLabelLayer.addLayer(L.marker([loc.latitude, loc.longitude],
                    { icon, interactive: false, keyboard: false }));
                return;
            }
            const r = (loc.records || [])[0];
            if (!r) return;
            if (r.category === 'hfc_listings' && r.price) {
                priceLabelLayer.addLayer(L.marker([loc.latitude, loc.longitude], {
                    icon: buildLabelIcon(priceLabelHtml('R ', formatPriceLong(r.price)), '60%'),
                    interactive: false, keyboard: false,
                }));
            } else if (r.category === 'sold_comps' && r.price) {
                priceLabelLayer.addLayer(L.marker([loc.latitude, loc.longitude], {
                    icon: buildLabelIcon(priceLabelHtml('Sold R ', formatPriceShort(r.price)), '60%'),
                    interactive: false, keyboard: false,
                }));
            }
        });

        applyLabelZoomVisibility();
    }

    function applyLabelZoomVisibility() {
        if (!map) return;
        const show = map.getZoom() >= LABEL_ZOOM_THRESHOLD;
        if (show && !map.hasLayer(schemeLabelLayer)) map.addLayer(schemeLabelLayer);
        else if (!show && map.hasLayer(schemeLabelLayer)) map.removeLayer(schemeLabelLayer);
        if (show && !map.hasLayer(priceLabelLayer))  map.addLayer(priceLabelLayer);
        else if (!show && map.hasLayer(priceLabelLayer))  map.removeLayer(priceLabelLayer);
    }

    // ── State ─────────────────────────────────────────────────────────────
    // POPIA owner-detail gate. Default Seller; Agent is opt-in only when
    // the server-rendered toggle says the user holds access_prospecting.
    // localStorage cannot escalate — the server-side gate enforces Seller
    // for unauthorised users regardless of what the client stores.
    const VIEW_MODE_CAN_AGENT = (document.getElementById('view-mode-toggle')?.dataset.canSeeAgent === '1');
    let viewMode = (function () {
        if (!VIEW_MODE_CAN_AGENT) return 'seller';
        const stored = localStorage.getItem('corex.map.view_mode');
        return stored === 'agent' ? 'agent' : 'seller';
    })();
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

    // ── Phase A.3.3 — URL state sync ──────────────────────────────────────
    // Shareable links: on load, the URL's query string takes precedence
    // over the saved-search default + localStorage. After every fetch,
    // history.replaceState updates the URL so the agent can copy/share it.
    //
    // Encoded keys (omitted when at default to keep URLs tidy):
    //   scope, q, types[], status[], sw,
    //   pn/px, bdn/bdx, btn/btx, sn/sx, bn/bx, dn/dx, yf/yt
    // Phase B Fix 1a — map view + layer state in URL.
    // Defaults (omitted from URL when at default):
    //   layers = all 6 enabled
    //   view (lat/lng/z) = HFC default fitBounds. Suppressed for one syncUrlState
    //   cycle after "Reset to HFC area" so the URL is clean post-reset.
    let suppressViewInUrl = false;
    const ALL_LAYER_KEYS = ['active_listings','hfc_listings','mic_subjects','scheme_owners','sold_comps','tracked_properties'];
    function buildUrlStateParams() {
        const p = new URLSearchParams();
        const set = (k, v) => { if (v !== null && v !== undefined && v !== '') p.set(k, String(v)); };
        if (filters.scope && filters.scope !== SCOPE_DEFAULT) set('scope', filters.scope);
        set('q', filters.search);
        if (filters.yearFrom !== null) set('yf', filters.yearFrom);
        if (filters.yearTo   !== null) set('yt', filters.yearTo);
        if (filters.types.length !== 4) filters.types.forEach(t => p.append('types[]', t));
        ['priceMin','priceMax','bedroomsMin','bedroomsMax','bathroomsMin','bathroomsMax',
         'standMin','standMax','buildingMin','buildingMax','domMin','domMax']
            .forEach(k => { if (filters[k] !== null) set(k, filters[k]); });
        if (filters.listingStatus.length) filters.listingStatus.forEach(s => p.append('status[]', s));
        if (filters.soldWindow) set('sw', filters.soldWindow);

        // Phase B Fix 1a — map center/zoom (5dp lat/lng is ≈1m at this latitude).
        if (!suppressViewInUrl && typeof map !== 'undefined' && map) {
            try {
                const c = map.getCenter();
                const z = map.getZoom();
                if (c && Number.isFinite(c.lat) && Number.isFinite(c.lng) && Number.isFinite(z)) {
                    set('lat', c.lat.toFixed(5));
                    set('lng', c.lng.toFixed(5));
                    set('z',   String(z));
                }
            } catch (e) { /* map not ready */ }
        }
        suppressViewInUrl = false;

        // Phase B Fix 1a — enabled layers (only when not the full default set).
        const enabled = Array.from(enabledLayers).sort();
        const isDefaultLayers = enabled.length === ALL_LAYER_KEYS.length
            && ALL_LAYER_KEYS.every(k => enabled.includes(k));
        if (!isDefaultLayers) set('layers', enabled.join(','));
        return p;
    }
    function syncUrlState() {
        try {
            const p = buildUrlStateParams();
            const qs = p.toString();
            const here = window.location.pathname;
            const next = qs ? (here + '?' + qs) : here;
            if (next !== window.location.pathname + window.location.search) {
                window.history.replaceState(null, '', next);
            }
        } catch (e) { /* history API guard for very old browsers */ }
    }
    function readUrlStateIntoFilters() {
        const p = new URLSearchParams(window.location.search);
        if (![...p.keys()].length) return false;

        const intOr = (k, fallback) => {
            if (!p.has(k)) return fallback;
            const n = parseInt(p.get(k), 10);
            return Number.isFinite(n) ? n : fallback;
        };
        const arr = (k) => p.getAll(k + '[]').length ? p.getAll(k + '[]') : null;

        if (p.has('scope') && ['my','agency','all'].includes(p.get('scope'))) {
            filters.scope = (p.get('scope') === 'all' && !SCOPE_IS_OWNER) ? 'agency' : p.get('scope');
        }
        if (p.has('q')) filters.search = p.get('q');
        filters.yearFrom = intOr('yf', filters.yearFrom);
        filters.yearTo   = intOr('yt', filters.yearTo);
        const types = arr('types'); if (types) filters.types = types;
        filters.priceMin     = intOr('priceMin',     filters.priceMin);
        filters.priceMax     = intOr('priceMax',     filters.priceMax);
        filters.bedroomsMin  = intOr('bedroomsMin',  filters.bedroomsMin);
        filters.bedroomsMax  = intOr('bedroomsMax',  filters.bedroomsMax);
        filters.bathroomsMin = intOr('bathroomsMin', filters.bathroomsMin);
        filters.bathroomsMax = intOr('bathroomsMax', filters.bathroomsMax);
        filters.standMin     = intOr('standMin',     filters.standMin);
        filters.standMax     = intOr('standMax',     filters.standMax);
        filters.buildingMin  = intOr('buildingMin',  filters.buildingMin);
        filters.buildingMax  = intOr('buildingMax',  filters.buildingMax);
        filters.domMin       = intOr('domMin',       filters.domMin);
        filters.domMax       = intOr('domMax',       filters.domMax);
        const statuses = arr('status'); if (statuses) filters.listingStatus = statuses;
        if (p.has('sw') && ['','3mo','6mo','12mo','24mo'].includes(p.get('sw'))) {
            filters.soldWindow = p.get('sw');
        }

        // Phase B Fix 1a — enabled layers (?layers=a,b,c). When the param
        // is present we overwrite the in-memory set; missing means "keep
        // the existing default (all on)".
        if (p.has('layers')) {
            const requested = p.get('layers').split(',').map(s => s.trim()).filter(Boolean);
            enabledLayers.clear();
            requested.forEach(k => { if (ALL_LAYER_KEYS.includes(k)) enabledLayers.add(k); });
            // Reflect on the left-rail buttons so the UI matches state.
            document.querySelectorAll('[data-layer-toggle]').forEach(btn => {
                const on = enabledLayers.has(btn.dataset.layerToggle);
                btn.dataset.on = on ? '1' : '0';
                paintLayerBtn(btn);
            });
        }
        return true;
    }

    // Phase B Fix 1a — read map view (lat/lng/zoom) directly from URL.
    // Returns {lat, lng, zoom} or null. Called inline at map init, before
    // the rest of readUrlStateIntoFilters() runs, because the view applies
    // to the Leaflet map (not to the filters object).
    function readMapViewFromUrl() {
        const p = new URLSearchParams(window.location.search);
        if (!p.has('lat') || !p.has('lng') || !p.has('z')) return null;
        const lat = parseFloat(p.get('lat'));
        const lng = parseFloat(p.get('lng'));
        const z   = parseInt(p.get('z'), 10);
        if (!Number.isFinite(lat) || !Number.isFinite(lng) || !Number.isFinite(z)) return null;
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180 || z < 1 || z > 22) return null;
        return { lat, lng, zoom: z };
    }

    // Phase 3g V2 — display mode + filter state.
    // Phase A.3.1 — extended with scope, search, range filters.
    const CURRENT_YEAR = {{ now()->year }};
    const SCOPE_DEFAULT = document.getElementById('scope-pills')?.dataset.default || 'agency';
    const SCOPE_IS_OWNER = (document.getElementById('scope-pills')?.dataset.owner === '1');
    const FILTER_DEFAULTS = {
        scope:    SCOPE_DEFAULT,
        search:   '',
        yearFrom: null,
        yearTo:   null,
        types:    ['house','sectional','townhouse','vacant'],
        priceMin: null,
        priceMax: null,
        bedroomsMin:  null,
        bedroomsMax:  null,
        bathroomsMin: null,
        bathroomsMax: null,
        standMin:     null,
        standMax:     null,
        buildingMin:  null,
        buildingMax:  null,
        listingStatus: [],
        soldWindow:    '',
        domMin: null,
        domMax: null,
    };
    let displayMode = localStorage.getItem('corex.map.display_mode') || 'pins';
    let filters = loadFiltersFromStorage();
    let heatLayer = null;

    function isStr(v) { return typeof v === 'string'; }
    function isNum(v) { return Number.isInteger(v); }
    function loadFiltersFromStorage() {
        try {
            const raw = localStorage.getItem('corex.map.filters_v2');
            if (!raw) return { ...FILTER_DEFAULTS, types: [...FILTER_DEFAULTS.types], listingStatus: [] };
            const f = JSON.parse(raw);
            const out = { ...FILTER_DEFAULTS };
            // Carry forward known keys, defaulting through when missing/wrong type.
            if (isStr(f.scope) && ['my','agency','all'].includes(f.scope)) out.scope = f.scope;
            if (!SCOPE_IS_OWNER && out.scope === 'all') out.scope = 'agency';
            if (isStr(f.search))    out.search   = f.search;
            if (isNum(f.yearFrom))  out.yearFrom = f.yearFrom;
            if (isNum(f.yearTo))    out.yearTo   = f.yearTo;
            out.types = Array.isArray(f.types) && f.types.length ? f.types : [...FILTER_DEFAULTS.types];
            ['priceMin','priceMax','bedroomsMin','bedroomsMax','bathroomsMin','bathroomsMax',
             'standMin','standMax','buildingMin','buildingMax','domMin','domMax']
                .forEach(k => { if (isNum(f[k])) out[k] = f[k]; });
            out.listingStatus = Array.isArray(f.listingStatus) ? f.listingStatus : [];
            if (isStr(f.soldWindow) && ['','3mo','6mo','12mo','24mo'].includes(f.soldWindow)) out.soldWindow = f.soldWindow;
            return out;
        } catch (e) {
            return { ...FILTER_DEFAULTS, types: [...FILTER_DEFAULTS.types], listingStatus: [] };
        }
    }
    function persistFilters() {
        localStorage.setItem('corex.map.filters_v2', JSON.stringify(filters));
    }
    function countActiveFilters() {
        let n = 0;
        if (filters.scope !== SCOPE_DEFAULT) n++;
        if (filters.search) n++;
        if (filters.yearFrom !== null) n++;
        if (filters.yearTo   !== null) n++;
        if (filters.types.length !== FILTER_DEFAULTS.types.length) n++;
        ['priceMin','priceMax','bedroomsMin','bedroomsMax','bathroomsMin','bathroomsMax',
         'standMin','standMax','buildingMin','buildingMax','domMin','domMax']
            .forEach(k => { if (filters[k] !== null) n++; });
        if (filters.listingStatus.length > 0) n++;
        if (filters.soldWindow) n++;
        return n;
    }
    function syncFilterUi() {
        // Scope pills.
        document.querySelectorAll('#scope-pills .scope-pill').forEach(btn => {
            const active = btn.dataset.scope === filters.scope;
            btn.style.background = active ? 'var(--brand-button)' : 'transparent';
            btn.style.color      = active ? '#fff' : 'var(--text-secondary)';
        });
        // Search.
        const searchEl = document.getElementById('filter-search');
        if (searchEl && searchEl.value !== filters.search) searchEl.value = filters.search;
        // Year range.
        document.getElementById('filter-year-from').value = filters.yearFrom ?? '';
        document.getElementById('filter-year-to').value   = filters.yearTo ?? '';
        // Property types.
        document.querySelectorAll('[data-filter-type]').forEach(cb => {
            cb.checked = filters.types.includes(cb.dataset.filterType);
        });
        // Numeric range inputs — paired (id_min, id_max) → state keys minK/maxK.
        const numericMap = [
            ['filter-price-min', 'priceMin'], ['filter-price-max', 'priceMax'],
            ['filter-bedrooms-min', 'bedroomsMin'], ['filter-bedrooms-max', 'bedroomsMax'],
            ['filter-bathrooms-min', 'bathroomsMin'], ['filter-bathrooms-max', 'bathroomsMax'],
            ['filter-stand-min', 'standMin'], ['filter-stand-max', 'standMax'],
            ['filter-building-min', 'buildingMin'], ['filter-building-max', 'buildingMax'],
            ['filter-dom-min', 'domMin'], ['filter-dom-max', 'domMax'],
        ];
        numericMap.forEach(([id, key]) => {
            const el = document.getElementById(id);
            if (el) el.value = filters[key] ?? '';
        });
        // Listing status.
        document.querySelectorAll('[data-filter-status]').forEach(cb => {
            cb.checked = filters.listingStatus.includes(cb.dataset.filterStatus);
        });
        // Sold window.
        const soldEl = document.getElementById('filter-sold-window');
        if (soldEl) soldEl.value = filters.soldWindow || '';
        // Active-filter chip count.
        const cnt = countActiveFilters();
        const chip = document.getElementById('filters-count-strip');
        if (chip) {
            chip.textContent = cnt + ' filter' + (cnt === 1 ? '' : 's') + ' active';
            chip.style.display = cnt > 0 ? 'block' : 'none';
        }
    }
    const enabledLayers = new Set([
        'hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners',
        'tracked_properties',
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
    // Phase B Fix 1a — URL view (?lat=&lng=&z=) takes precedence over
    // HFC_BOUNDS. Falls back to fitBounds when URL params are absent or
    // out of range. Done after map creation so setView/fitBounds run on
    // the live instance.
    const map = L.map('corex-map', { zoomControl: true, attributionControl: true });
    const __urlView = readMapViewFromUrl();
    if (__urlView) {
        map.setView([__urlView.lat, __urlView.lng], __urlView.zoom);
    } else {
        map.fitBounds([
            [HFC_BOUNDS.south, HFC_BOUNDS.west],
            [HFC_BOUNDS.north, HFC_BOUNDS.east],
        ]);
    }

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
        // Fix-up #2 §1 — N₁ raised 13 → 15. At z=13 with the previous
        // threshold the Margate area un-clustered into a visual collision
        // pile (200+ pins overlapping). At z=15 the un-cluster transition
        // happens with enough pixel separation that each bucket's shape
        // is legible. Scheme + price labels still appear at z >= 16, so
        // un-cluster (15) precedes labels (16) by one zoom — clean stepping.
        disableClusteringAtZoom: 15,
        maxClusterRadius: 40,
        chunkedLoading: true,
        spiderfyOnMaxZoom: false,
        showCoverageOnHover: false,
        zoomToBoundsOnClick: true,
        // Spec §5 — clusters are themed per dominant bucket of their
        // children. The default orange MarkerCluster CSS still loads
        // (the link tag stays) but our className wins specificity.
        iconCreateFunction: clusterIcon,
    });
    cluster.addTo(map);

    // ── Helpers ───────────────────────────────────────────────────────────
    /**
     * Build the Leaflet icon for a location. Three paths (A.2.3 Item 2):
     *   - 'scheme'    → distinct rounded-rectangle PURPLE pin with unit count
     *                   (sectional title building, all records are owners)
     *   - 'composite' → slate square + teal accent + count badge (mixed cats)
     *   - 'single'    → category-coloured circle + letter
     *
     * Fallback when display_as is missing on a payload: infer from
     * record_count + records[].category to stay compatible with cached
     * pre-A.2.3 payloads.
     */
    function locationIcon(loc, isSelected) {
        const selectedClass = isSelected ? ' corex-pin--selected' : '';
        const display = loc.display_as
            || (loc.record_count > 1
                ? ((loc.records || []).every(r => r.category === 'scheme_owners') ? 'scheme' : 'composite')
                : 'single');

        // Scheme pin — O triangle with unit-count badge.
        if (display === 'scheme') {
            const style = PIN_STYLES.O;
            const html = '<div style="position:relative;width:' + style.size[0] + 'px;height:' + style.size[1] + 'px;">'
                + shapeSvg(style)
                + glyphLayer(style)
                + countBadge(loc.record_count, style.fill)
                + '</div>';
            return L.divIcon({
                html,
                className: 'corex-pin corex-pin-triangle' + selectedClass,
                iconSize:   style.size,
                iconAnchor: [style.size[0] / 2, style.size[1] / 2],
            });
        }

        // Composite or single. Primary record drives the shape; composite
        // adds concentric coloured rings for the other buckets present
        // (fix-up §4).
        const primary = (loc.records || [])[0] || {};
        const pinKey  = pinKeyForRecord(primary);
        const style   = PIN_STYLES[pinKey] || PIN_STYLES.M;

        // Inner content per pin type:
        //   H / S_own → agency logo (house-pin geometry)
        //   everything else → glyph letter
        const inner = (pinKey === 'H' || pinKey === 'S_own') ? housePinInner() : glyphLayer(style);

        // Own-sold gets the red "S" corner badge so the agent reads
        // "ours" at a glance without overlaying anything on the logo.
        const ownSoldBadge = style.ownSoldBadge ? ownSoldCornerBadge() : '';

        // Composite: concentric coloured rings + count + overflow pill.
        const ringsVisible = (display === 'composite') ? compositeRingBuckets(loc).slice(0, 3) : [];
        const ringExt = ringRadialExtent(ringsVisible.length);
        const ringHtml = (display === 'composite') ? compositeRingsSvg(loc, style.size) : '';
        const overflow = (display === 'composite') ? ringOverflowPill(loc) : '';
        const count    = (display === 'composite') ? countBadge(loc.record_count, style.fill) : '';

        // Wrapper size grows to enclose the rings so Leaflet doesn't clip
        // them. The primary pin is centred inside the wrapper.
        const W = style.size[0] + 2 * ringExt;
        const H = style.size[1] + 2 * ringExt;

        const html = '<div style="position:relative;width:' + W + 'px;height:' + H + 'px;">'
            + ringHtml
            + '<div style="position:absolute;left:' + ringExt + 'px;top:' + ringExt
            +     'px;width:' + style.size[0] + 'px;height:' + style.size[1] + 'px;">'
            +   shapeSvg(style) + inner + ownSoldBadge
            + '</div>'
            + count + overflow
            + '</div>';

        return L.divIcon({
            html,
            className: 'corex-pin corex-pin-' + style.shape + selectedClass,
            iconSize:   [W, H],
            iconAnchor: [W / 2, H / 2],
        });
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

            // A.2.3 — re-derive display_as after filtering since the category
            // mix may have changed (a multi-data composite filtered down to
            // only scheme_owners becomes a 'scheme' visual).
            let displayAs;
            if (kept.length === 1)                                                    displayAs = 'single';
            else if (kept.every(r => r.category === 'scheme_owners'))                 displayAs = 'scheme';
            else                                                                      displayAs = 'composite';

            out.push({
                ...loc,
                records: kept,
                record_count: kept.length,
                is_composite: kept.length > 1,
                primary_category: kept[0].category,
                categories_present: Array.from(new Set(kept.map(r => r.category))),
                display_as: displayAs,
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
    function actionsForRecord(record, sourceContext, locationKey, card) {
        const recId = record.id;
        const baseLog = {
            category:     record.category,
            location_key: locationKey,
            source:       sourceContext, // 'single_detail' | 'composite_row'
        };

        switch (record.category) {
            case 'hfc_listings': {
                // A.2.3 Item 4 — portal strip. When the listing is published
                // on a portal, return one action per active portal so the
                // single-detail CTA block renders them as a row of pill-style
                // buttons. When the listing is sold or has no portal URLs at
                // all, fall through to the internal "Open record →" button.
                if (typeof recId !== 'number') return [];
                const internalUrl = record.internal_url || PROPERTY_SHOW_URL_TPL.replace('__ID__', String(recId));
                const isSold      = record.status === 'sold';
                const urls        = record.public_listing_urls || {};
                const portalDefs  = [
                    { key: 'p24', label: 'P24', tint: '#dc2626' },
                    { key: 'pp',  label: 'PP',  tint: '#1d4ed8' },
                    { key: 'hfc', label: 'HFC', tint: '#00d4aa' },
                ];
                const activePortals = portalDefs.filter(p => urls[p.key]);

                if (!isSold && activePortals.length > 0) {
                    return activePortals.map(p => ({
                        key:       'listing_opened',
                        label:     p.label,
                        iconLabel: 'Open listing on ' + p.label,
                        iconSvg:   portalPillSvg(p.label, p.tint),
                        style:     'portal-pill',
                        portalTint: p.tint,
                        destUrl:   urls[p.key],
                        newTab:    true,
                        logPayload:{ ...baseLog, action: 'listing_opened', record_id: recId, portal: p.key },
                    }));
                }

                // Sold or unsyndicated — internal record only.
                return [{
                    key:       'pitch_launched',
                    label:     'Open record →',
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
                // A.2.5 — collision detection. card.prospect_status tells us
                // HFC's existing relationship to this address; we pick the
                // CTA accordingly. When the card hasn't loaded yet (composite-
                // row icons), default to the "available" path — the agent
                // hits the same collision check at click time via the
                // prospect_launched activity-log endpoint.
                const ps = (card && card.prospect_status) ? card.prospect_status : { status: 'available' };
                const prospectAction = {
                    key:       'prospect_launched',
                    label:     'Prospect Now →',
                    iconLabel: 'Prospect this property',
                    iconSvg:   ICON_FIND,
                    style:     'primary',
                    destUrl:   MIC_OPPORTUNITIES_URL,
                    // Phase B Fix 1b — prospect flow is a separate workflow surface (MIC).
                    // Open in new tab so the agent can return to the map view.
                    newTab:    true,
                    awaitServerRedirect: true,
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
                };

                switch (ps.status) {
                    case 'held': {
                        const openUrl = PROPERTY_SHOW_URL_TPL.replace('__ID__', String(ps.property_id || 0));
                        return [{
                            key:       'open_property',
                            label:     'Open property record →',
                            iconLabel: 'Already on HFC books — open record',
                            iconSvg:   ICON_OPEN,
                            style:     'primary',
                            destUrl:   openUrl,
                            // Phase B Fix 1b — property record is a separate workflow surface.
                            newTab:    true,
                            banner:    { tone: 'info', text: 'Already on HFC books' },
                            logPayload:{ ...baseLog, action: 'listing_opened', record_id: ps.property_id, portal: 'hfc' },
                        }];
                    }
                    case 'own_draft': {
                        const openUrl = PROPERTY_SHOW_URL_TPL.replace('__ID__', String(ps.property_id || 0));
                        const days = ps.days_in_state ?? 0;
                        return [{
                            key:       'open_property',
                            label:     'Continue your draft (' + days + 'd) →',
                            iconLabel: 'Continue draft',
                            iconSvg:   ICON_OPEN,
                            style:     'primary',
                            destUrl:   openUrl,
                            // Phase B Fix 1b — draft editor is a separate workflow surface.
                            newTab:    true,
                            logPayload:{ ...baseLog, action: 'listing_opened', record_id: ps.property_id, portal: 'hfc' },
                        }];
                    }
                    case 'other_draft': {
                        const agent = ps.agent_name || 'another agent';
                        const days  = ps.days_in_state ?? 0;
                        const state = ps.state_label || 'draft';
                        return [
                            {
                                key:       'coordinate_with',
                                label:     'Coordinate with ' + agent + ' (' + days + 'd in ' + state + ')',
                                iconLabel: 'Coordinate with this agent',
                                iconSvg:   ICON_WHATSAPP,
                                style:     'primary',
                                destUrl:   '#', // no nav — clicking opens an info toast
                                // Phase B Fix 1b — disableNav means newTab is academic, but flip
                                // for consistency with the other workflow CTAs in this case.
                                newTab:    true,
                                disableNav: true,
                                logPayload:{ ...baseLog, action: 'prospect_launched', record_id: String(recId) },
                            },
                            {
                                key:       'prospect_override',
                                label:     'Override and prospect anyway',
                                iconLabel: 'Override coordination + prospect',
                                iconSvg:   ICON_FIND,
                                style:     'secondary',
                                destUrl:   null,
                                // Phase B Fix 1b — override modal continues to prospect_launched (separate workflow).
                                newTab:    true,
                                // Tells handleActionClick to open the override
                                // modal (collects reason) → fires prospect_override
                                // activity event → then proceeds with the
                                // normal prospect_launched flow.
                                overrideProspect: {
                                    propertyId:        ps.property_id,
                                    originalAgentId:   ps.original_agent_id ?? null,
                                    originalAgentName: ps.agent_name ?? null,
                                    daysInState:       days,
                                    locationKey,
                                    sourceContext,
                                    prospectPayload:   prospectAction.logPayload,
                                },
                            },
                        ];
                    }
                    case 'previously_sold': {
                        return [
                            { ...prospectAction,
                              label:  'New prospect anyway →',
                              banner: { tone: 'warn',
                                        text: 'Previously sold by HFC' + (ps.sale_date ? ' (' + ps.sale_date + ')' : '') },
                            },
                        ];
                    }
                    case 'previously_held': {
                        return [
                            { ...prospectAction,
                              label:  'Prospect anyway →',
                              banner: { tone: 'warn',
                                        text: 'Previously held by HFC' + (ps.expired_at ? ' (expired ' + ps.expired_at + ')' : '') + (ps.agent_name ? ' by ' + ps.agent_name : '') },
                            },
                        ];
                    }
                    case 'available':
                    default:
                        return [prospectAction];
                }
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

            case 'tracked_properties': {
                // Phase B Fix 2+3 — T-pins get TWO CTAs:
                //   1. "WhatsApp / Pitch →" (primary) — contact-capture modal
                //      mirroring the MIC Work-tab fromProspecting flow.
                //   2. "Open in MIC →" (secondary) — the existing MIC
                //      opportunities deep-link, retained as a fallback.
                // Both open in a new tab (Fix 1b) since they navigate to
                // separate workflow surfaces.
                const tpId = record.tracked_property_id ?? (typeof recId === 'number' ? recId : null);
                if (tpId === null) return [];
                return [
                    {
                        key:       'whatsapp_launched',
                        label:     'WhatsApp / Pitch →',
                        iconLabel: 'WhatsApp / pitch this tracked property',
                        iconSvg:   ICON_WHATSAPP,
                        style:     'primary',
                        destUrl:   TP_OUTREACH_TPL.replace('__ID__', String(tpId)),
                        newTab:    true,
                        logPayload:{ ...baseLog, action: 'whatsapp_launched', record_id: tpId, channel: 'whatsapp' },
                    },
                    {
                        key:       'cma_opened',
                        label:     'Open in MIC →',
                        iconLabel: 'Open in MIC opportunities',
                        iconSvg:   ICON_OPEN,
                        style:     'secondary',
                        destUrl:   '/corex/market-intelligence/opportunities/' + tpId,
                        newTab:    true,
                        logPayload:{ ...baseLog, action: 'cma_opened', record_id: tpId },
                    },
                ];
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
    // A.2.3 Item 4 — coloured text pill used by the portal strip (P24/PP/HFC).
    // Rendered as inline HTML (not an SVG) so the icon-strip helper can drop
    // it in via innerHTML alongside the other inline SVGs.
    function portalPillSvg(label, tint) {
        return '<span style="display:inline-flex;align-items:center;justify-content:center;'
            + 'background:' + tint + ';color:#fff;font-weight:700;font-size:11px;'
            + 'letter-spacing:0.5px;padding:3px 7px;border-radius:4px;line-height:1;">'
            + label + '</span>';
    }

    const ICON_PITCH      = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 2 11 13"></path><path d="M22 2 15 22l-4-9-9-4 20-7Z"></path></svg>';
    const ICON_WHATSAPP   = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5Z"></path></svg>';
    const ICON_COMPARABLE = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3h18v18H3z"></path><path d="M3 9h18M9 3v18"></path></svg>';
    const ICON_FIND       = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><path d="m21 21-4.3-4.3"></path></svg>';
    const ICON_OPEN       = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><path d="M15 3h6v6"></path><path d="m10 14 11-11"></path></svg>';
    // A.2.4 — copy icon for sensitive-fact rows.
    const ICON_COPY       = '<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';

    // A.2.4 — brief "Copied ✓" toast. Anchored to the right panel; auto
    // dismisses after 1.5s. Uses inline DOM injection to avoid pulling in
    // a global toast library on the standalone map page.
    // A.2.5 — extended to a generic info-toast helper.
    let copyToastEl = null;
    function toastCopied() { _toast('Copied ✓', 1500); }
    function toastInfo(msg) { _toast(msg, 2500); }
    function _toast(text, ms) {
        if (!copyToastEl) {
            copyToastEl = document.createElement('div');
            copyToastEl.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#0f172a;color:#fff;padding:8px 14px;border-radius:6px;font-size:0.8125rem;font-weight:500;box-shadow:0 4px 12px rgba(0,0,0,.25);z-index:9999;opacity:0;transition:opacity 150ms;pointer-events:none;max-width:320px;';
            document.body.appendChild(copyToastEl);
        }
        copyToastEl.textContent = text;
        copyToastEl.style.opacity = '1';
        clearTimeout(copyToastEl._t);
        copyToastEl._t = setTimeout(() => { copyToastEl.style.opacity = '0'; }, ms);
    }

    /**
     * A.2.5 — "Override and prospect anyway" modal. Builds a one-shot DOM
     * modal asking the agent why they're stepping on another agent's
     * draft. ≥ 20 chars required. On confirm:
     *   1. POST prospect_override to /map/activity/log (BM audit trail)
     *   2. POST prospect_launched (the original action) via the same
     *      awaitServerRedirect flow the available-state CTA would use
     *   3. Navigate to opportunities.show
     */
    function openProspectOverrideModal(act) {
        // Remove an existing modal if the agent rapidly opens twice.
        const existing = document.getElementById('map-prospect-override-modal');
        if (existing) existing.remove();

        const op = act.overrideProspect;
        const wrap = document.createElement('div');
        wrap.id = 'map-prospect-override-modal';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);padding:24px;';
        wrap.innerHTML =
            '<div style="background:var(--surface);border-radius:8px;max-width:480px;width:100%;padding:24px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">'
            +   '<h3 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin:0 0 8px 0;">Override coordination</h3>'
            +   '<p style="font-size:0.8125rem;color:var(--text-muted);margin:0 0 14px 0;line-height:1.4;">'
            +     'Why are you overriding ' + escapeHtml(op.originalAgentName || 'this agent') + '\'s draft? '
            +     'This reason is logged for Branch Manager visibility.'
            +   '</p>'
            +   '<textarea id="override-reason-input" rows="4" placeholder="Minimum 20 characters — be specific." '
            +     'style="width:100%;padding:8px;border:1px solid var(--border);border-radius:4px;background:var(--surface-2);color:var(--text-primary);font-family:inherit;font-size:0.8125rem;resize:vertical;box-sizing:border-box;"></textarea>'
            +   '<div id="override-reason-counter" style="font-size:0.6875rem;color:var(--text-muted);margin-top:4px;text-align:right;">0 / 20</div>'
            +   '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px;">'
            +     '<button type="button" id="override-cancel" style="padding:8px 16px;font-size:0.8125rem;font-weight:500;background:transparent;border:1px solid var(--border);border-radius:6px;color:var(--text-primary);cursor:pointer;">Cancel</button>'
            +     '<button type="button" id="override-confirm" disabled style="padding:8px 16px;font-size:0.8125rem;font-weight:600;background:#00d4aa;border:1px solid #00d4aa;border-radius:6px;color:#0f172a;cursor:not-allowed;opacity:0.4;">Confirm override</button>'
            +   '</div>'
            + '</div>';
        document.body.appendChild(wrap);

        const ta      = wrap.querySelector('#override-reason-input');
        const counter = wrap.querySelector('#override-reason-counter');
        const confirm = wrap.querySelector('#override-confirm');
        const cancel  = wrap.querySelector('#override-cancel');

        ta.addEventListener('input', () => {
            const len = ta.value.trim().length;
            counter.textContent = len + ' / 20';
            const ok = len >= 20;
            confirm.disabled = !ok;
            confirm.style.cursor = ok ? 'pointer' : 'not-allowed';
            confirm.style.opacity = ok ? '1' : '0.4';
        });

        cancel.addEventListener('click', () => wrap.remove());
        wrap.addEventListener('click', (e) => { if (e.target === wrap) wrap.remove(); });

        confirm.addEventListener('click', async () => {
            const reason = ta.value.trim();
            if (reason.length < 20) return;

            // 1) Audit the override decision.
            try {
                await fetch(MAP_ACTIVITY_URL, {
                    method:  'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    credentials: 'same-origin',
                    body: JSON.stringify({
                        action:              'prospect_override',
                        category:            'active_listings',
                        record_id:           String(op.prospectPayload.record_id || ''),
                        location_key:        op.locationKey || '',
                        source:              op.sourceContext || 'single_detail',
                        property_id:         op.propertyId,
                        original_agent_id:   op.originalAgentId,
                        original_agent_name: op.originalAgentName,
                        days_in_state:       op.daysInState,
                        override_reason:     reason,
                    }),
                });
            } catch (err) { /* swallow — audit failure must not block the prospect */ }

            wrap.remove();

            // 2) Now fire the actual prospect_launched + redirect.
            //    Same explicit-error contract as handleActionClick — when
            //    the server can't resolve the prospecting_listing we
            //    surface the failure instead of silently sending the
            //    agent to MIC opportunities.
            try {
                const resp = await fetch(MAP_ACTIVITY_URL, {
                    method:  'POST',
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN },
                    credentials: 'same-origin',
                    body: JSON.stringify(op.prospectPayload),
                });
                if (resp.ok) {
                    const body = await resp.json();
                    if (body.error === 'pitch_unavailable') {
                        toastInfo(body.error_message || 'Could not start a pitch for this record.');
                        return;
                    }
                    if (body.redirect_url) {
                        window.location.href = body.redirect_url;
                        return;
                    }
                }
                toastInfo('Could not start a pitch right now. Please try again.');
            } catch (err) {
                toastInfo('Network error starting the pitch. Please try again.');
            }
        });

        setTimeout(() => ta.focus(), 50);
    }

    function currentBounds() {
        const b = map.getBounds();
        return {
            north: b.getNorth(), south: b.getSouth(),
            east: b.getEast(),   west: b.getWest(),
        };
    }

    function boundsKey(b) {
        // Phase A.3.1 — fingerprint covers scope/search/all new range filters
        // so a filter change always busts the cache.
        const fp = JSON.stringify({
            sc: filters.scope, q: filters.search,
            yf: filters.yearFrom, yt: filters.yearTo,
            tp: filters.types.slice().sort(),
            pn: filters.priceMin, px: filters.priceMax,
            bdn: filters.bedroomsMin, bdx: filters.bedroomsMax,
            btn: filters.bathroomsMin, btx: filters.bathroomsMax,
            sn: filters.standMin, sx: filters.standMax,
            bn: filters.buildingMin, bx: filters.buildingMax,
            ls: filters.listingStatus.slice().sort(),
            sw: filters.soldWindow,
            dn: filters.domMin, dx: filters.domMax,
        });
        return [b.south, b.west, b.north, b.east, viewMode, includeDemo ? '1' : '0', displayMode, fp, Array.from(enabledLayers).sort().join(',')]
            .map(v => typeof v === 'number' ? v.toFixed(4) : v).join('|');
    }

    function setLoading(on) {
        document.getElementById('map-loading-pill').style.display = on ? 'inline-flex' : 'none';
    }

    // ── Selected-pin highlight ────────────────────────────────────────────
    // Tracks the location_key of the pin currently shown in the detail panel
    // so the marker can render with a halo ring. State persists across
    // fetchPins() reloads (filter toggles, pan/zoom) — the marker may be
    // re-created, but the new instance picks up the selected class from its
    // location_key matching `selectedLocationKey`.
    let selectedLocationKey = null;
    let markerByLocationKey = new Map();

    /** Toggle the halo class on a marker's icon element. Safe on clustered
     *  markers (getElement returns null while clustered — the class is also
     *  baked into the divIcon HTML at render so it survives un-clustering). */
    function applyHaloToMarker(marker, on) {
        if (!marker) return;
        const el = marker.getElement && marker.getElement();
        if (el) el.classList.toggle('corex-pin--selected', !!on);
    }

    function selectLocation(locationKey) {
        if (selectedLocationKey === locationKey) return;
        const prev = selectedLocationKey ? markerByLocationKey.get(selectedLocationKey) : null;
        if (prev) applyHaloToMarker(prev, false);
        selectedLocationKey = locationKey || null;
        const next = selectedLocationKey ? markerByLocationKey.get(selectedLocationKey) : null;
        if (next) applyHaloToMarker(next, true);
    }

    function clearSelection() {
        if (!selectedLocationKey) return;
        const prev = markerByLocationKey.get(selectedLocationKey);
        if (prev) applyHaloToMarker(prev, false);
        selectedLocationKey = null;
    }

    /** Apply the pulse class to a DOM element. Force a reflow between
     *  remove/add so a second hover restarts the animation rather than
     *  no-oping. */
    function fireElementPulse(el) {
        if (!el) return;
        el.classList.remove('corex-pin--pulse');
        void el.offsetWidth;
        el.classList.add('corex-pin--pulse');
        setTimeout(() => { el.classList.remove('corex-pin--pulse'); }, 2200);
    }

    /** Sidebar→map hover preview — fix-up §5 + fix-up #2 §3. Pulses the
     *  matching marker briefly so the agent scanning the sidebar can see
     *  which pin the card refers to.
     *  Cluster-aware: when the marker is INSIDE a cluster, pulse the
     *  visible cluster icon instead — the agent at least sees WHICH
     *  cluster bubble contains the card they're hovering. Returns true
     *  when something visible pulsed, false otherwise (caller may decide
     *  to surface a "pin off-map" hint). */
    function pulseMarkerByLocationKey(locationKey) {
        if (!locationKey) return false;
        const m = markerByLocationKey.get(locationKey);
        if (!m) return false;
        // Direct path: marker is on the map at the current zoom.
        const el = m.getElement && m.getElement();
        if (el) {
            fireElementPulse(el);
            return true;
        }
        // Clustered path: ask the markercluster plugin for the visible
        // parent and pulse that. Available on Leaflet.markercluster >=1.4.
        if (cluster && cluster.getVisibleParent) {
            const parent = cluster.getVisibleParent(m);
            const parentEl = parent && parent !== m && parent._icon ? parent._icon : null;
            if (parentEl) {
                fireElementPulse(parentEl);
                return true;
            }
        }
        return false;
    }

    /** Sidebar→map click — fix-up §5 + fix-up #2 §3. Pan/zoom the map so
     *  the matching pin is centred AND visible (un-cluster if needed),
     *  then apply the selected halo. zoomToShowLayer is the cluster-aware
     *  path — it figures out the minimum zoom that un-clusters the marker
     *  and pans to it. Halo is applied in its callback so it doesn't try
     *  to attach to a not-yet-mounted DOM node. */
    function panToLocationKey(locationKey, opts) {
        if (!locationKey) return;
        const m = markerByLocationKey.get(locationKey);
        if (!m) return;
        const latlng = m.getLatLng && m.getLatLng();
        if (!latlng) return;
        // disableClusteringAtZoom = 15 — make sure we land at or above it
        // so the marker is rendered as an individual pin (not a cluster).
        const targetZoom = Math.max(map.getZoom(), 15);

        const applySelectAfterMount = () => {
            // Defer to the next animation frame so Leaflet has mounted
            // the marker element after the (un-)cluster transition.
            requestAnimationFrame(() => selectLocation(locationKey));
        };

        if (cluster && cluster.zoomToShowLayer && cluster.hasLayer(m) && !m.getElement()) {
            // Marker is currently inside a cluster bubble. Let the plugin
            // pick the zoom that surfaces it, then select.
            cluster.zoomToShowLayer(m, applySelectAfterMount);
            return;
        }
        const animate = !(opts && opts.skipAnimate);
        map.setView(latlng, targetZoom, { animate });
        // setView fires a moveend after the animation — apply select then
        // so the marker DOM exists. Fallback: also try immediately for
        // the no-animation path.
        map.once('moveend', applySelectAfterMount);
        if (!animate) applySelectAfterMount();
    }

    /** Map pin → sidebar — fix-up #2 §3 reverse direction. Briefly
     *  highlights the panel area so the agent's eye is drawn to the
     *  newly-loaded content. Pure CSS class toggle on the panel root —
     *  no scroll, since the panel is fixed-position and always visible.
     *  Defensive: panel element may not exist on degenerate pages. */
    function flashPanelHighlight() {
        // Panel id is map-detail-panel (set on the right-rail aside);
        // double-check before mutating in case the element was removed
        // by a future refactor.
        const panel = document.getElementById('map-detail-panel');
        if (!panel) return;
        panel.classList.remove('corex-panel--flash');
        void panel.offsetWidth;
        panel.classList.add('corex-panel--flash');
        setTimeout(() => panel.classList.remove('corex-panel--flash'), 900);
    }

    function clearAllPins() {
        if (cluster) cluster.clearLayers();
        // Re-index from scratch on each render; selectedLocationKey stays
        // so the next render re-applies the halo to the matching marker.
        markerByLocationKey = new Map();
    }

    function renderPayload(payload) {
        lastPayload = payload;
        clearAllPins();

        // Update left-rail counts from layer_counts (post-filter, pre-grouping).
        // MAP-CAP — when a layer was truncated by its per-layer cap, the badge
        // shows the LIMIT followed by "+" (e.g. "1000+") rather than the
        // exact post-dedup count — an honest density signal that reads as
        // "this is at least the cap, and more exists beyond it". When not
        // truncated, the badge shows the exact count as before.
        const counts = payload.layer_counts || {};
        const cappedSet = new Set(payload.capped_layers || []);
        const layerLimits = payload.layer_limits || {};
        Object.keys(LAYER_COLOURS).forEach(key => {
            const badge = document.querySelector('[data-layer-count="' + key + '"]');
            if (badge) {
                if (cappedSet.has(key) && layerLimits[key]) {
                    badge.textContent = String(layerLimits[key]) + '+';
                } else {
                    badge.textContent = String(counts[key] ?? 0);
                }
            }
        });

        // Apply layer toggles client-side (re-derives composite flags).
        const filtered = applyLayerFilters(payload);

        // [M-TRACE] #2 post-group — count M-bearing locations in the
        // post-filter set. m_locations = total locations with at least one
        // M record; m_orphans = locations whose ONLY records are M
        // (is_cma_orphan=true). REMOVE after staging confirmation.
        (function () {
            let mLocs = 0, mOrphans = 0;
            (filtered.locations || []).forEach(loc => {
                const recs = loc.records || [];
                const hasM = recs.some(r => r.category === 'mic_subjects');
                if (!hasM) return;
                mLocs += 1;
                if (recs.every(r => r.category === 'mic_subjects')) mOrphans += 1;
            });
            console.log('[M-TRACE] post-group:', JSON.stringify({
                total_locations: (filtered.locations || []).length,
                m_locations: mLocs,
                m_orphans: mOrphans,
                enabled_layers: Array.from(enabledLayers).sort(),
            }));
        })();

        const showPins = displayMode === 'pins' || displayMode === 'both';
        const showHeat = displayMode === 'heatmap' || displayMode === 'both';
        const heatPoints = [];

        let total = 0;
        let _mTraceAdded = 0;
        (filtered.locations || []).forEach(loc => {
            heatPoints.push([loc.latitude, loc.longitude, 1.0]);
            total += loc.record_count;
            if (!showPins) return;

            const _bucket = bucketForLocation(loc);
            // Fix-up #3 §1 — also resolve the primary category (mic_subjects /
            // hfc_listings / …) so the DOM carries a queryable attribute the
            // PROOF console queries can target directly. This is the
            // contract behind:
            //   document.querySelectorAll('[data-bucket="mic_subjects"]')
            // returning the M marker count in the DOM.
            const _primaryCat = (loc.display_as === 'scheme')
                ? 'scheme_owners'
                : (((loc.records || [])[0] || {}).category || 'unknown');
            const m = L.marker([loc.latitude, loc.longitude], {
                icon:   locationIcon(loc, loc.location_key === selectedLocationKey),
                // Stamp the bucket so the themed cluster iconCreateFunction
                // can tally child markers per bucket without re-deriving.
                bucket: _bucket,
                primaryCategory: _primaryCat,
            });
            // Project the bucket + category onto the rendered icon element
            // as soon as Leaflet mounts it, so dev-tools can query directly
            // (see proof procedure in fix-up #3 §1). The marker may mount
            // and un-mount as the user pans (cluster/un-cluster), so we
            // listen for every 'add' rather than just the first one.
            m.on('add', function () {
                const el = m.getElement && m.getElement();
                if (el) {
                    el.setAttribute('data-bucket', _bucket);
                    el.setAttribute('data-category', _primaryCat);
                    el.setAttribute('data-location-key', loc.location_key || '');
                }
            });
            markerByLocationKey.set(loc.location_key, m);
            // A.2.6 — hover_summary is built server-side per location with a
            // 5-priority cascade. The client just renders it. Fallback path
            // kicks in only on legacy cached payloads from pre-A.2.6.
            const hs = loc.hover_summary;
            const tooltipLines = [];
            if (hs && (hs.title || hs.subtitle)) {
                if (hs.title)    tooltipLines.push(hs.title);
                if (hs.subtitle) tooltipLines.push(hs.subtitle);
                if (hs.footer)   tooltipLines.push(hs.footer);
            } else if (loc.is_composite) {
                tooltipLines.push((loc.geocode_target || (loc.records[0]?.title) || '').toUpperCase());
                tooltipLines.push(loc.record_count + ' records here — click to list');
            } else {
                const rec = loc.records[0];
                tooltipLines.push(rec.title || '');
                if (rec.subtitle) tooltipLines.push(rec.subtitle);
            }
            m.bindTooltip(tooltipLines.filter(Boolean).join('\n'), { direction: 'top' });

            m.on('click', () => {
                selectLocation(loc.location_key);
                if (loc.is_composite) {
                    openCompositeList(loc);
                } else {
                    // Single-record click — go straight to the detail view,
                    // no back arrow (there's no list to go back to).
                    openSingleDetail(loc.records[0], null, loc.location_key);
                }
                // Reverse direction (fix-up #2 §3): flash the right-panel
                // so the agent's eye lands on the freshly-opened content.
                flashPanelHighlight();
            });
            cluster.addLayer(m);

            // [M-TRACE] #3 adding-M-marker — fire once per M-bearing
            // location actually added to the cluster. Logs the classNames
            // Leaflet will write onto the marker element so we can correlate
            // with DOM querySelector counts. REMOVE after staging confirms.
            if (_primaryCat === 'mic_subjects') {
                _mTraceAdded += 1;
                const _iconOpts = (m.options.icon && m.options.icon.options) || {};
                console.log('[M-TRACE] adding-M-marker:', JSON.stringify({
                    location_key: loc.location_key,
                    lat: loc.latitude,
                    lng: loc.longitude,
                    html_classes: _iconOpts.className || '(none)',
                    icon_size: _iconOpts.iconSize || null,
                    display_as: loc.display_as,
                    records: (loc.records || []).length,
                }));
            }
        });

        // [M-TRACE] #4 m-markers-in-dom — count what actually landed in the
        // DOM. Three queries because the marker may render via three
        // different class hooks depending on shape:
        //   .corex-pin-square    — M's specific shape class
        //   [data-category=...]  — fix-up #3 §1 dataset attribute
        //   .leaflet-marker-icon — Leaflet's universal marker class
        // The cluster may keep some markers off-DOM if they're inside a
        // cluster bubble at the current zoom; the dom_total figure
        // captures only the currently-visible markers, NOT the cluster's
        // internal layer count. REMOVE after staging confirms.
        (function () {
            const domSquare   = document.querySelectorAll('.corex-pin-square').length;
            const domCat      = document.querySelectorAll('[data-category="mic_subjects"]').length;
            const domBucket   = document.querySelectorAll('[data-bucket="CMA"]').length;
            const domTotalMk  = document.querySelectorAll('.leaflet-marker-icon').length;
            const clusterChildren = (cluster && cluster.getLayers) ? cluster.getLayers().length : 0;
            console.log('[M-TRACE] m-markers-in-dom:', JSON.stringify({
                m_added_this_render: _mTraceAdded,
                dom_corex_pin_square: domSquare,
                dom_data_category_mic_subjects: domCat,
                dom_data_bucket_CMA: domBucket,
                dom_leaflet_marker_icon_total: domTotalMk,
                cluster_total_layers: clusterChildren,
                current_zoom: (map && map.getZoom) ? map.getZoom() : null,
            }));
        })();

        document.getElementById('empty-state').style.display = total === 0 ? 'block' : 'none';

        // Capped-layers warning — reuses the V1 banner. Map internal layer
        // keys (e.g. 'scheme_owners') to the human-friendly LAYER_NAMES
        // before joining, so the agent sees "Truncated: Sectional Schemes"
        // not the raw db column name (fix-up #2 free find).
        const capEl = document.getElementById('layer-cap-notice');
        const capped = payload.capped_layers || [];
        if (capped.length > 0) {
            capEl.textContent = 'Truncated: ' + capped.map(k => LAYER_NAMES[k] || k).join(', ');
            capEl.style.display = 'block';
        } else {
            capEl.style.display = 'none';
        }

        renderHeatmap(showHeat ? heatPoints : []);
        document.getElementById('heat-legend').style.display = showHeat ? 'block' : 'none';

        // Spec §3 — scheme + price labels at Z ≥ 16. Rebuild from the
        // filtered locations so toggling layers also refreshes labels.
        rebuildLabelLayers(filtered);

        // Fix-up #2 §2 — render invariant. If a layer's chip count is
        // non-zero but ZERO locations in the rendered payload carry that
        // category, log a warn so future regressions surface immediately
        // (this is exactly the M-missing failure mode that took two
        // staging rounds to diagnose). Dev-only: gated on hostname so it
        // stays silent in production.
        assertLayerCountsMatchRendered(payload, filtered);

        // Fix-up #3 §1 — stronger invariant. The previous check only
        // looked at the filtered PAYLOAD; this one inspects the actual
        // MARKER COLLECTION inside the cluster and counts by primary
        // category. If the payload says "59 M records, 56 locations"
        // but only 0-or-few markers carry primaryCategory='mic_subjects',
        // we know the JS marker-creation pipeline silently dropped them.
        // This is the diagnostic the user can run themselves via the
        // dev-tools console contract above. Gated to staging/localhost.
        reportClusterMarkersByCategory(payload);
    }

    /** Map invariant: for every layer with chip count > 0 the rendered
     *  payload must include at least one location whose records contain
     *  that category. Cluster-collapse + applyLayerFilters can both
     *  legitimately drop records (e.g. M collapsed into cma_info when a
     *  non-CMA peer exists), so the comparison uses the SERVER payload's
     *  locations (pre-filter) for the orphan baseline. Triggers on the
     *  staging hostname only to keep prod logs clean. */
    function assertLayerCountsMatchRendered(serverPayload, filteredPayload) {
        if (!serverPayload || !filteredPayload) return;
        try {
            if (!/(localhost|127\.0|staging|hfc-staging|\.localhost)/i.test(location.hostname)) return;
        } catch (e) { return; }

        const counts = serverPayload.layer_counts || {};
        const renderedCats = new Set();
        (filteredPayload.locations || []).forEach(loc => {
            (loc.records || []).forEach(r => renderedCats.add(r.category));
        });
        Object.keys(counts).forEach(layer => {
            if ((counts[layer] || 0) === 0) return;
            if (!enabledLayers.has(layer)) return;
            if (!renderedCats.has(layer)) {
                console.warn('[CoreX Map] Invariant broken — layer "' + layer
                    + '" reports ' + counts[layer]
                    + ' records but ZERO rendered. Likely causes: server-side '
                    + 'collapse (e.g. M collapsed into cma_info when a non-CMA '
                    + 'peer exists), aggressive filter, or a regression in '
                    + 'locationIcon for this bucket. Check applyLayerFilters '
                    + 'and renderPayload at the marker creation site.');
            }
        });
    }

    /** Marker-collection-level invariant. After render, walks
     *  cluster.getLayers() and tallies markers by primaryCategory option.
     *  Logs an info table to the console so the user / on-call can
     *  copy-paste it into a bug report. Logs a warn when an enabled,
     *  non-zero-count layer has ZERO markers — the precise failure mode
     *  the M-missing complaints reported. Staging/localhost only. */
    function reportClusterMarkersByCategory(serverPayload) {
        try {
            if (!/(localhost|127\.0|staging|hfc-staging|\.localhost)/i.test(location.hostname)) return;
        } catch (e) { return; }
        if (!cluster || !cluster.getLayers) return;

        const layers = cluster.getLayers();
        const byCat = {};
        layers.forEach(layer => {
            const cat = (layer.options && layer.options.primaryCategory) || 'unknown';
            byCat[cat] = (byCat[cat] || 0) + 1;
        });
        const counts = (serverPayload && serverPayload.layer_counts) || {};

        // Surface as a single console table so the diff between "server
        // says X records" and "DOM has Y markers" is immediately visible.
        const report = Object.keys({ ...counts, ...byCat }).sort().map(cat => ({
            category:      cat,
            server_count:  counts[cat] || 0,
            markers_in_cluster: byCat[cat] || 0,
            enabled:       enabledLayers.has(cat) ? 'yes' : 'no',
        }));
        if (typeof console.table === 'function') console.table(report);

        // Hard-fail invariant: enabled layer with server>0 but markers=0.
        report.forEach(row => {
            if (row.enabled === 'yes' && row.server_count > 0 && row.markers_in_cluster === 0) {
                console.warn('[CoreX Map] FIX-UP #3 §1 invariant broken — "'
                    + row.category + '" has ' + row.server_count
                    + ' server records but 0 markers in the cluster. '
                    + 'Markers were not added to the cluster — check '
                    + 'applyLayerFilters output and the marker-creation '
                    + 'loop in renderPayload. To inspect the DOM manually: '
                    + 'document.querySelectorAll(\'[data-category="' + row.category
                    + '"]\').length;');
            }
        });
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
        // Always send all six layer keys so server gives counts for each;
        // the UI hides disabled ones at render time.
        ['hfc_listings','sold_comps','active_listings','mic_subjects','scheme_owners','tracked_properties'].forEach(k => params.append('layers[]', k));

        // Filters — only send when narrowed beyond defaults so the URL stays
        // clean in the common case.
        if (filters.scope && filters.scope !== 'agency') params.set('scope', filters.scope);
        if (filters.search) params.set('search', filters.search);
        if (filters.yearFrom !== null) params.set('dateFromYear', String(filters.yearFrom));
        if (filters.yearTo   !== null) params.set('dateToYear',   String(filters.yearTo));
        if (filters.types.length !== FILTER_DEFAULTS.types.length) {
            filters.types.forEach(t => params.append('propertyTypes[]', t));
        }
        if (filters.priceMin !== null) params.set('priceMin', String(filters.priceMin));
        if (filters.priceMax !== null) params.set('priceMax', String(filters.priceMax));
        // Phase A.3.1 — range filters.
        const rangePairs = [
            ['bedroomsMin','bedroomsMax'],
            ['bathroomsMin','bathroomsMax'],
            ['standMin','standMax'],
            ['buildingMin','buildingMax'],
            ['domMin','domMax'],
        ];
        rangePairs.forEach(([lo, hi]) => {
            if (filters[lo] !== null) params.set(lo, String(filters[lo]));
            if (filters[hi] !== null) params.set(hi, String(filters[hi]));
        });
        if (filters.listingStatus.length > 0) {
            filters.listingStatus.forEach(s => params.append('listingStatus[]', s));
        }
        if (filters.soldWindow) params.set('soldWindow', filters.soldWindow);

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

            // [M-TRACE] #1 server-response — count records returned per
            // category. If 'mic_subjects' is non-zero here, the bug is
            // downstream of the network layer (grouping / marker create /
            // DOM insertion / CSS). REMOVE this log after staging confirms
            // M renders correctly (see follow-up ticket in this file).
            (function () {
                const byCat = {};
                let total = 0;
                (payload && payload.locations || []).forEach(loc => {
                    (loc.records || []).forEach(r => {
                        byCat[r.category] = (byCat[r.category] || 0) + 1;
                        total += 1;
                    });
                });
                console.log('[M-TRACE] server-response:', JSON.stringify({
                    total: total,
                    by_category: byCat,
                    layer_counts: payload && payload.layer_counts || {},
                    locations: (payload && payload.locations || []).length,
                }));
            })();

            // LRU cache
            cache.push({ key: key, payload: payload });
            if (cache.length > CACHE_MAX) cache.shift();

            renderPayload(payload);
            // Phase A.3.3 — keep the URL in sync after every successful fetch
            // so the agent can copy/share the link and reproduce the view.
            syncUrlState();
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
    // A.2.4 — track the currently-open panel state so a view-mode toggle can
    // re-fetch and re-render WITHOUT losing the user's place.
    let panelState = 'closed';        // 'closed' | 'composite_list' | 'single_detail'
    let panelCurrentRecord = null;    // record dict shown in single_detail (if any)
    let panelCurrentLocationKey = null;

    function setPanelOpen(open) {
        detailPanel.style.transform = open ? 'translateX(0)' : 'translateX(100%)';
        if (!open) panelState = 'closed';
    }

    document.getElementById('detail-close-btn').addEventListener('click', () => {
        setPanelOpen(false);
        panelParentComposite = null;
        panelCurrentRecord = null;
        panelCurrentLocationKey = null;
        clearSelection();
        document.getElementById('detail-back-btn').style.display = 'none';
    });

    document.getElementById('detail-back-btn').addEventListener('click', () => {
        if (panelParentComposite) openCompositeList(panelParentComposite);
    });

    // Fix-up #2 §3 — single-detail mode sidebar↔map link. The detail
    // header is the persistent "card" for the open record; hovering it
    // pulses the matching pin (whether un-clustered or inside a cluster
    // bubble — pulseMarkerByLocationKey handles both). Clicking it pans
    // the map back to the pin (useful when the agent has scrolled the
    // map away from the open card). Hooked once at init via the static
    // header DOM (id="detail-title"); reads panelCurrentLocationKey at
    // event time so it always points at the current record.
    (function wireSingleDetailHeaderLink() {
        const titleEl = document.getElementById('detail-title');
        const subtitleEl = document.getElementById('detail-subtitle');
        // Treat the header block as a single hover target. Cursor hint
        // signals it's interactive without spoiling the layout.
        [titleEl, subtitleEl].forEach(el => {
            if (!el) return;
            el.style.cursor = 'pointer';
            el.addEventListener('mouseenter', () => {
                if (panelState === 'single_detail' && panelCurrentLocationKey) {
                    pulseMarkerByLocationKey(panelCurrentLocationKey);
                }
            });
            el.addEventListener('click', () => {
                if (panelState === 'single_detail' && panelCurrentLocationKey) {
                    panToLocationKey(panelCurrentLocationKey);
                }
            });
        });
    })();

    /**
     * Render the composite list — every record at this location, each
     * clickable to drill into single_detail with a back arrow.
     */
    function openCompositeList(loc) {
        panelParentComposite = loc;
        panelState = 'composite_list';
        panelCurrentRecord = null;
        panelCurrentLocationKey = loc.location_key;

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
            const name   = LAYER_NAMES[cat]   || cat;
            const recs   = byCategory[cat];
            // Fix-up #3 §3 — sidebar bucket icon uses the SAME shape +
            // colour + glyph as the map pin for this category. Single
            // source: sidebarBucketIcon() reuses shapeSvg + glyphLayer
            // from PIN_STYLES.
            const icon = sidebarBucketIcon(cat, 18);

            html += '<div style="margin-bottom:14px;">'
                +    '<div style="display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:0.6875rem;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;">'
                +      icon
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

        // Wire row clicks → single_detail. Click ALSO pans the map to the
        // pin (fix-up §5). Hover on any element in the row pulses the
        // matching pin so the agent can map sidebar → map at a glance.
        // Every record in a composite shares the SAME location_key so
        // hover/click on any row pulses/centres the same pin.
        document.querySelectorAll('[data-record-idx]').forEach(btn => {
            btn.addEventListener('click', () => {
                const idx = parseInt(btn.dataset.recordIdx, 10);
                openSingleDetail(loc.records[idx], loc, loc.location_key);
                panToLocationKey(loc.location_key);
            });
        });
        document.querySelectorAll('[data-record-row]').forEach(row => {
            // mouseenter (not mouseover) avoids re-firing on every inner
            // child hover; pulse is idempotent within its 1s window.
            row.addEventListener('mouseenter', () => {
                pulseMarkerByLocationKey(loc.location_key);
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
        panelState = 'single_detail';
        panelCurrentRecord = record;
        panelCurrentLocationKey = locationKey;
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

        // A.2.5 — local "card just loaded" reference. CTA renderer reads
        // prospect_status off this for the active_listings collision logic.
        let loadedCard = null;

        document.getElementById('detail-title').textContent = record.title || '';
        document.getElementById('detail-subtitle').textContent = record.subtitle || '';
        document.getElementById('detail-facts').innerHTML = '<div style="font-size:0.75rem;color:var(--text-muted);">Loading…</div>';
        document.getElementById('detail-relationships').innerHTML = '';
        document.getElementById('detail-sensitive').style.display = 'none';
        document.getElementById('detail-address').style.display = 'none';
        document.getElementById('detail-ctas').innerHTML = '';
        setPanelOpen(true);

        // T layer short-circuit — the bounds-query payload already carries
        // every field the detail panel needs (street_number, street_name,
        // property_type, erf_number, geo_confidence, first_seen_at,
        // tracked_property_id). No fetch needed; the canonical full-detail
        // page is the MIC opportunities surface and is wired as a CTA
        // ("Open in MIC →") rather than a detail-card endpoint.
        // Fixes the JSON parse error caused by detail_url previously
        // pointing at a 301 redirect that returned HTML.
        if (record.category === 'tracked_properties') {
            renderTrackedPropertyInline(record);
            renderSingleDetailCtas(record, locationKey, null);
            return;
        }

        const url = record.deep_link || record.detail_url;
        if (url) {
            try {
                const fullUrl = url + (url.includes('?') ? '&' : '?') + 'viewMode=' + viewMode;
                const resp = await fetch(fullUrl, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
                if (resp.ok) {
                    loadedCard = await resp.json();
                    renderCard(loadedCard);
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

        // A.2.5 — pass the loaded card (carries prospect_status for Portal
        // Stock) into the CTA renderer so the collision states can pick the
        // right CTA. CTAs still render on card-load failure (with no card).
        renderSingleDetailCtas(record, locationKey, loadedCard);
    }

    /**
     * Render the primary/secondary CTAs in the single-detail panel and wire
     * each one to fire its activity log POST + default <a> navigation.
     */
    function renderSingleDetailCtas(record, locationKey, card) {
        const acts = actionsForRecord(record, 'single_detail', locationKey, card);
        const host = document.getElementById('detail-ctas');
        if (acts.length === 0) {
            host.innerHTML = '';
            return;
        }

        // A.2.3 Item 4 — when actions are portal pills (HFC active listing),
        // render them as a HORIZONTAL strip with a leading "View listing on:"
        // label. Other action types stay in the vertical full-width layout.
        const isPortalStrip = acts.every(a => a.style === 'portal-pill');
        if (isPortalStrip) {
            host.style.flexDirection = 'row';
            host.style.alignItems    = 'center';
            host.style.flexWrap      = 'wrap';
            host.innerHTML =
                '<span style="font-size:0.75rem;color:var(--text-muted);margin-right:4px;">View listing on:</span>'
                + acts.map(act =>
                    '<a href="' + escapeAttr(act.destUrl || '#') + '" '
                    + 'data-map-action="' + escapeAttr(act.key) + '" '
                    + (act.newTab ? 'target="_blank" rel="noopener" ' : '')
                    + 'title="' + escapeAttr(act.iconLabel) + '" '
                    + 'style="display:inline-flex;align-items:center;justify-content:center;padding:4px;border-radius:4px;text-decoration:none;border:1px solid transparent;transition:border-color 150ms;" '
                    + 'onmouseover="this.style.borderColor=\'#00d4aa\';" '
                    + 'onmouseout="this.style.borderColor=\'transparent\';">'
                    + act.iconSvg
                    + '</a>'
                ).join('');
        } else {
            host.style.flexDirection = 'column';
            host.style.alignItems    = 'stretch';
            host.style.flexWrap      = 'nowrap';
            // A.2.5 — actions may carry a banner descriptor that renders ABOVE
            // the CTA (used by collision states: "Already on HFC books",
            // "Previously sold by HFC", etc.).
            host.innerHTML = acts.map(act => {
                const stylePrimary   = 'background:#00d4aa;color:#0f172a;border:1px solid #00d4aa;';
                const styleSecondary = 'background:transparent;color:#00d4aa;border:1px solid #00d4aa;';
                const styleStr = act.style === 'secondary' ? styleSecondary : stylePrimary;
                const disabled = !act.destUrl && !act.awaitServerRedirect && !act.overrideProspect;
                let html = '';
                if (act.banner) {
                    const tones = {
                        info: { bg: 'rgba(14,165,233,0.10)', border: '#0ea5e9', color: '#0ea5e9' },
                        warn: { bg: 'rgba(245,158,11,0.10)', border: '#d97706', color: '#d97706' },
                    };
                    const t = tones[act.banner.tone] || tones.info;
                    html += '<div style="padding:8px 10px;border-radius:6px;font-size:0.75rem;font-weight:500;background:' + t.bg + ';border:1px solid ' + t.border + ';color:' + t.color + ';">'
                        +     escapeHtml(act.banner.text)
                        + '</div>';
                }
                // awaitServerRedirect actions intercept the click via JS and
                // open the new tab themselves (we need a handle on the new
                // window so we can swap its URL once the fetch resolves).
                // Rendering target="_blank" on the anchor would cause the
                // browser to ALSO open the destUrl in a new tab on click —
                // a duplicate / wrong-URL tab next to ours. The href
                // becomes '#' so middle-click is a no-op (and Cmd/Ctrl+click
                // can't accidentally bypass the JS handler and land on the
                // MIC destUrl fallback).
                const renderNewTab = act.newTab && !act.awaitServerRedirect;
                const hrefAttr     = act.awaitServerRedirect ? '#' : (act.destUrl || '#');
                html += '<a href="' + escapeAttr(hrefAttr) + '" '
                    + 'data-map-action="' + escapeAttr(act.key) + '" '
                    + (renderNewTab ? 'target="_blank" rel="noopener" ' : '')
                    + (disabled ? 'aria-disabled="true" ' : '')
                    + 'style="display:flex;align-items:center;justify-content:center;gap:8px;width:100%;min-height:40px;padding:8px 12px;border-radius:6px;font-size:0.8125rem;font-weight:600;text-decoration:none;transition:opacity 150ms;'
                    +    styleStr + (disabled ? 'opacity:0.5;cursor:not-allowed;' : '') + '">'
                    + '<span style="display:inline-flex;align-items:center;">' + act.iconSvg + '</span>'
                    + '<span>' + escapeHtml(act.label) + '</span>'
                    + '</a>';
                return html;
            }).join('');
        }

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
        // A.2.5 — "Override and prospect anyway" → open the reason modal
        // first. After the agent supplies ≥ 20 chars and confirms, fire
        // prospect_override (audit), then proceed to the standard
        // awaitServerRedirect prospect launch.
        if (act.overrideProspect) {
            e.preventDefault();
            openProspectOverrideModal(act);
            return;
        }
        // A.2.5 — "Coordinate with X" placeholder click. The action carries
        // disableNav to keep the user in the panel; we show a small toast
        // and log the click for visibility.
        if (act.disableNav) {
            e.preventDefault();
            if (act.logPayload) fireActivityLog(act.logPayload);
            toastInfo('Coordination panel coming soon — message the agent directly for now.');
            return;
        }
        if (act.awaitServerRedirect) {
            e.preventDefault();
            e.stopPropagation();
            // Pre-open the new tab SYNCHRONOUSLY while we still have the
            // user gesture — Chrome/Firefox/Safari block window.open() that
            // fires after an await boundary. We assign the real URL once
            // the server response lands. If the server returns no URL
            // (pitch_unavailable from MapActivityController) we close the
            // placeholder and surface an explicit error.
            //
            // CRITICAL: do NOT pass 'noopener' here. window.open() with
            // noopener returns null in every browser, which means we lose
            // the popup handle — the placeholder tab stays on about:blank
            // forever AND our `if (popup) popup.location.href = url; else
            // window.location.href = url` cascade falls into the ELSE
            // branch and navigates THE MAP TAB. After we've assigned
            // popup.location.href below we sever popup.opener manually to
            // restore the security property `noopener` was after.
            const popup = act.newTab ? window.open('about:blank', '_blank') : null;
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
                    // Server may explicitly flag any error condition
                    // (pitch_unavailable for soft-deleted / cross-agency /
                    // unsupported record_id; pitch_blocked_other_draft for
                    // another agent's active draft on this address;
                    // pitch_blocked_held / pitch_blocked_own_draft when
                    // the listing matches an existing HFC property — those
                    // two carry a redirect_url pointing at the property
                    // record). Honour ALL of them — do NOT fall back to
                    // MIC, do NOT silently navigate without surfacing the
                    // error_message. The post-fix-up #3 regression of
                    // "Prospect Now → MIC" was the entry-point's collision
                    // redirect kicking in AFTER the popup navigated; we
                    // now surface that collision HERE so the popup either
                    // closes (other_draft) or lands directly on the
                    // property (held / own_draft).
                    if (body.error) {
                        toastInfo(body.error_message || 'Could not start a pitch for this record.');
                        if (body.redirect_url && act.newTab && popup) {
                            popup.location.href = body.redirect_url;
                            try { popup.opener = null; } catch (_) { /* cross-origin harmless */ }
                        } else if (body.redirect_url && !act.newTab) {
                            window.location.href = body.redirect_url;
                        } else if (popup) {
                            popup.close();
                        }
                        return;
                    }
                    const url = body.redirect_url;
                    if (url) {
                        if (act.newTab) {
                            if (popup) {
                                popup.location.href = url;
                                // Restore the security property `noopener`
                                // would have given us — break the back-ref
                                // so the entry-point page can't reach
                                // window.opener and tamper with the map tab.
                                try { popup.opener = null; } catch (_) { /* cross-origin will throw, harmless */ }
                            } else {
                                // Browser blocked window.open() entirely.
                                // Surface a clear message — do NOT navigate
                                // the map tab as a fallback.
                                toastInfo('Pop-up blocked. Allow pop-ups for this site and try again.');
                            }
                        } else {
                            window.location.href = url;
                        }
                        return;
                    }
                }
                // Non-2xx, or 200 with neither redirect_url nor error —
                // treat as a transient hiccup. Close the popup, tell the
                // agent, do not bounce to MIC.
                if (popup) popup.close();
                toastInfo('Could not start a pitch right now. Please try again.');
            } catch (err) {
                if (popup) popup.close();
                toastInfo('Network error starting the pitch. Please try again.');
            }
            return;
        }
        if (!act.destUrl) { e.preventDefault(); return; }
        fireActivityLog(act.logPayload);
        // newTab handled by the anchor's target=_blank attribute; default
        // <a> navigation continues.
    }

    /**
     * Inline detail render for tracked_properties layer. The bounds-query
     * payload carries every field the panel needs, so we skip the
     * round-trip fetch and build the card from the record directly.
     *
     * Renders address + property type + erf number + GPS confidence +
     * first seen date as structured facts, plus a "Promoted: No" badge
     * (the query already filters out promoted rows). The "Open in MIC →"
     * CTA is wired by renderSingleDetailCtas() via actionsForRecord().
     */
    function renderTrackedPropertyInline(record) {
        const addrEl = document.getElementById('detail-address');
        const street = [record.street_number, record.street_name].filter(s => s && String(s).trim() !== '').join(' ');
        const addressLine = street !== '' ? (street + (record.suburb ? ', ' + record.suburb : '')) : (record.suburb || '');
        if (addressLine !== '') {
            addrEl.textContent = addressLine;
            addrEl.style.display = 'block';
        }

        const facts = [];
        if (record.property_type) {
            facts.push({ label: 'Property type', value: record.property_type });
        }
        if (record.erf_number) {
            facts.push({ label: 'Erf number', value: 'Erf ' + record.erf_number });
        }
        if (record.geo_confidence) {
            facts.push({ label: 'GPS precision', value: record.geo_confidence });
        }
        if (record.geo_source) {
            facts.push({ label: 'GPS source', value: record.geo_source });
        }
        if (record.first_seen_at || record.date) {
            const raw = record.first_seen_at || record.date;
            const shortDate = typeof raw === 'string' ? raw.slice(0, 10) : '';
            if (shortDate) {
                facts.push({ label: 'First seen', value: shortDate });
            }
        }
        // Promoted gate — the bounds-query already excludes promoted rows,
        // so this is always "No" when the pin is on the map. Surfacing it
        // explicitly so the agent knows the prospect hasn't been pitched
        // to stock yet.
        facts.push({ label: 'On agency books', value: 'No (prospect candidate)' });

        const factsHtml = facts.map(f =>
            '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--border);font-size:0.8125rem;">'
            + '<span style="color:var(--text-muted);">' + escapeHtml(f.label) + '</span>'
            + '<span style="color:var(--text-primary);font-weight:500;">' + escapeHtml(String(f.value)) + '</span>'
            + '</div>'
        ).join('');
        document.getElementById('detail-facts').innerHTML = factsHtml
            || '<div style="font-size:0.75rem;color:var(--text-muted);">No facts available.</div>';

        // Tracked properties have no sensitive_facts and no relationships
        // (the MIC opportunities surface is the canonical detail page).
        document.getElementById('detail-sensitive').style.display = 'none';
        document.getElementById('detail-relationships').innerHTML = '';
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
            // A.2.4 — facts may carry {copyable, va_lookup, value_raw} so
            // we render Copy ID / Lookup via VA action buttons inline.
            const sensitiveHtml = card.sensitive_facts.map((f, idx) =>
                '<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;padding:4px 0;font-size:0.8125rem;">'
                +   '<span style="color:var(--text-muted);">' + escapeHtml(f.label) + '</span>'
                +   '<span style="display:inline-flex;align-items:center;gap:6px;min-width:0;">'
                +     '<span style="color:var(--text-primary);font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + escapeHtml(String(f.value)) + '</span>'
                +     (f.copyable
                        ? '<button type="button" class="map-copy-btn" data-fact-idx="' + idx + '" '
                            + 'title="Copy" '
                            + 'style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-muted);cursor:pointer;transition:all 150ms;font-size:11px;line-height:1;" '
                            + 'onmouseover="this.style.color=\'#00d4aa\';this.style.borderColor=\'#00d4aa\';" '
                            + 'onmouseout="this.style.color=\'var(--text-muted)\';this.style.borderColor=\'var(--border)\';">'
                            + ICON_COPY + '</button>'
                        : '')
                +     (f.va_lookup
                        ? '<button type="button" class="map-va-btn" data-fact-idx="' + idx + '" '
                            + 'title="Lookup via Virtual Agent (coming soon)" '
                            + 'style="display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-muted);cursor:not-allowed;opacity:0.55;font-size:9px;font-weight:700;line-height:1;">'
                            + 'VA</button>'
                        : '')
                +   '</span>'
                + '</div>'
            ).join('');
            document.getElementById('detail-sensitive-facts').innerHTML = sensitiveHtml;
            document.getElementById('detail-sensitive').style.display = 'block';

            // Wire copy buttons. Each one copies the unmasked value to
            // clipboard, toasts "Copied ✓", and fires the id_copied
            // activity-log event when the fact is an ID (va_lookup=true).
            document.querySelectorAll('.map-copy-btn').forEach(btn => {
                btn.addEventListener('click', async (e) => {
                    e.stopPropagation();
                    const idx = parseInt(btn.dataset.factIdx, 10);
                    const fact = card.sensitive_facts[idx];
                    if (!fact) return;
                    const raw = fact.value_raw ?? fact.value;
                    try {
                        await navigator.clipboard.writeText(String(raw));
                        toastCopied();
                    } catch (err) {
                        // Best-effort fallback for older browsers.
                        const ta = document.createElement('textarea');
                        ta.value = String(raw); document.body.appendChild(ta);
                        ta.select(); document.execCommand('copy'); ta.remove();
                        toastCopied();
                    }
                    if (fact.va_lookup && panelCurrentRecord) {
                        // ID copy → log to agent_activity_events for PII audit.
                        fireActivityLog({
                            action:       'id_copied',
                            category:     panelCurrentRecord.category,
                            record_id:    String(panelCurrentRecord.id),
                            location_key: panelCurrentLocationKey || '',
                            source:       'single_detail',
                        });
                    }
                });
            });
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
    // Phase A.3.1 — compact icon-row toggles (replaces label+checkbox row).
    function paintLayerBtn(btn) {
        const on = btn.dataset.on === '1';
        btn.style.opacity = on ? '1' : '0.35';
        btn.style.borderColor = on ? 'transparent' : 'var(--border)';
    }
    // Fix-up #3 §3 — inject the SAME pin SVG (from sidebarBucketIcon /
    // PIN_STYLES) into each layer-toggle's pin-icon placeholder. Single
    // source: when palette or shape changes, only PIN_STYLES needs an
    // edit; the left-rail chips pick it up automatically on next load.
    document.querySelectorAll('[data-layer-pin-icon]').forEach(host => {
        const cat = host.dataset.layerPinIcon;
        // House pin is 24×28; everything else 22×22-ish. Render to a
        // size that fits within the 30×30 button container with breathing
        // room for the count badge.
        host.innerHTML = sidebarBucketIcon(cat, 24);
    });
    document.querySelectorAll('[data-layer-toggle]').forEach(btn => {
        paintLayerBtn(btn);
        btn.addEventListener('click', () => {
            const key = btn.dataset.layerToggle;
            const on  = btn.dataset.on !== '1';
            btn.dataset.on = on ? '1' : '0';
            paintLayerBtn(btn);
            if (on) enabledLayers.add(key);
            else    enabledLayers.delete(key);

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
            // POPIA gate — block client-side escalation to Agent View
            // when the user lacks access_prospecting. The server enforces
            // Seller regardless, but this stops the UI from misleading
            // the user into thinking PII is available.
            if (target === 'agent' && !VIEW_MODE_CAN_AGENT) return;
            viewMode = target;
            localStorage.setItem('corex.map.view_mode', target);
            document.getElementById('seller-banner').style.display = target === 'seller' ? 'block' : 'none';
            document.querySelectorAll('#view-mode-toggle .mode-pill').forEach(b => {
                const active = b.dataset.mode === target;
                b.classList.toggle('active', active);
                b.style.background = active ? 'var(--brand-button)' : 'transparent';
                b.style.color = active ? '#fff' : 'var(--text-secondary)';
            });
            // A.2.4 — sectional schemes stay visible in Seller View (identity
            // is redacted server-side). Pre-A.2.3 we hid the layer entry; that
            // CSS hide is removed now.

            // Clear cache (server response shape differs) + refetch.
            cache.length = 0;
            fetchPins();

            // A.2.4 — if a record detail or composite list is currently open,
            // re-fetch with the new view mode so the redaction applies LIVE.
            if (panelState === 'single_detail' && panelCurrentRecord) {
                openSingleDetail(panelCurrentRecord, panelParentComposite, panelCurrentLocationKey);
            } else if (panelState === 'composite_list' && panelParentComposite) {
                // Composite list is built from the in-memory location object —
                // a fresh fetch updates that location with the new view's
                // records the next time the user opens it. No immediate
                // re-render needed; the list shows what's already in memory
                // until the fetch lands and the user clicks the pin again.
                // (Closing + reopening the composite isn't worth the flicker.)
            }
        });
    });

    document.getElementById('reset-bounds-btn').addEventListener('click', () => {
        // Phase B Fix 1a — suppress lat/lng/z encoding for the next
        // syncUrlState() cycle so the URL is clean after reset. The
        // ensuing moveend will trigger a fetch which calls syncUrlState;
        // the flag prevents the new HFC-area center from being re-pinned.
        suppressViewInUrl = true;
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

    // Phase A.3.1 — Stock Scope pills.
    document.querySelectorAll('#scope-pills .scope-pill').forEach(btn => {
        btn.addEventListener('click', () => {
            const target = btn.dataset.scope;
            if (target === filters.scope) return;
            if (target === 'all' && !SCOPE_IS_OWNER) return;
            filters.scope = target;
            persistFilters();
            syncFilterUi();
            cache.length = 0;
            fetchPins();
        });
    });

    // Phase A.3.1 — pull current state from the inputs. Called by Apply +
    // the search debounce.
    function readFiltersFromUi() {
        const intOrNull = (id) => {
            const v = document.getElementById(id)?.value;
            const n = parseInt(v, 10);
            return (v === '' || !Number.isFinite(n)) ? null : n;
        };
        const types = Array.from(document.querySelectorAll('[data-filter-type]'))
            .filter(cb => cb.checked).map(cb => cb.dataset.filterType);
        const listingStatus = Array.from(document.querySelectorAll('[data-filter-status]'))
            .filter(cb => cb.checked).map(cb => cb.dataset.filterStatus);
        const sw = document.getElementById('filter-sold-window')?.value || '';
        const search = (document.getElementById('filter-search')?.value || '').trim();

        return {
            scope:    filters.scope,
            search:   search,
            yearFrom: intOrNull('filter-year-from'),
            yearTo:   intOrNull('filter-year-to'),
            types:    types.length ? types : [...FILTER_DEFAULTS.types],
            priceMin:     intOrNull('filter-price-min'),
            priceMax:     intOrNull('filter-price-max'),
            bedroomsMin:  intOrNull('filter-bedrooms-min'),
            bedroomsMax:  intOrNull('filter-bedrooms-max'),
            bathroomsMin: intOrNull('filter-bathrooms-min'),
            bathroomsMax: intOrNull('filter-bathrooms-max'),
            standMin:     intOrNull('filter-stand-min'),
            standMax:     intOrNull('filter-stand-max'),
            buildingMin:  intOrNull('filter-building-min'),
            buildingMax:  intOrNull('filter-building-max'),
            listingStatus,
            soldWindow:   ['','3mo','6mo','12mo','24mo'].includes(sw) ? sw : '',
            domMin:       intOrNull('filter-dom-min'),
            domMax:       intOrNull('filter-dom-max'),
        };
    }

    function applyFilters() {
        filters = readFiltersFromUi();
        persistFilters();
        syncFilterUi();
        cache.length = 0;
        fetchPins();
    }

    // Apply button — primary commit gesture.
    document.getElementById('filter-apply').addEventListener('click', applyFilters);

    // Clear All — reset to defaults (preserves scope per role default).
    document.getElementById('filter-clear').addEventListener('click', () => {
        filters = { ...FILTER_DEFAULTS, types: [...FILTER_DEFAULTS.types], listingStatus: [] };
        persistFilters();
        syncFilterUi();
        cache.length = 0;
        fetchPins();
    });

    // Search input — 500ms debounce per spec.
    let searchDebounceTimer = null;
    const searchInput = document.getElementById('filter-search');
    if (searchInput) {
        searchInput.value = filters.search;
        searchInput.addEventListener('input', () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(applyFilters, 500);
        });
    }

    // Enter inside a numeric input or checkbox change in a <details> block —
    // commit via Apply path so the user doesn't have to chase the button.
    document.querySelectorAll('#filters-body input, #filters-body select').forEach(el => {
        const evt = (el.tagName === 'INPUT' && (el.type === 'checkbox' || el.type === 'radio')) || el.tagName === 'SELECT'
            ? 'change' : 'change';
        el.addEventListener(evt, applyFilters);
        if (el.type === 'number' || el.type === 'text') {
            el.addEventListener('keydown', e => {
                if (e.key === 'Enter') { e.preventDefault(); applyFilters(); }
            });
        }
    });

    syncFilterUi();

    // ── Phase A.3.2 — Saved searches ─────────────────────────────────────
    // In-memory mirror of the user's saved searches. Refreshed via
    // refreshSavedSearchList() after every successful create/update/delete.
    let savedSearches = [];
    let pendingDefaultId = null; // applied after the list loads on first render

    function csrfFetch(url, opts) {
        const o = { ...(opts || {}) };
        o.headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF_TOKEN, ...(o.headers || {}) };
        if (o.body && !o.headers['Content-Type']) o.headers['Content-Type'] = 'application/json';
        o.credentials = 'same-origin';
        return fetch(url, o);
    }

    async function refreshSavedSearchList(applyDefault) {
        try {
            const resp = await csrfFetch(SAVED_SEARCH_INDEX_URL);
            if (!resp.ok) return;
            const body = await resp.json();
            savedSearches = Array.isArray(body.saved_searches) ? body.saved_searches : [];
            renderSavedSearchSelect();
            if (applyDefault) {
                // Phase A.3.3 — URL params take precedence over the
                // saved-search default. If the URL is empty, apply the
                // default; otherwise the URL parse already populated
                // filters in the boot block below.
                const hasUrl = window.location.search.length > 1;
                const def = savedSearches.find(s => s.is_default);
                if (def && !hasUrl) {
                    applySavedSearch(def);
                    document.getElementById('saved-search-select').value = String(def.id);
                }
            }
        } catch (e) { /* ignore — saved-search list is optional UX */ }
    }

    function renderSavedSearchSelect() {
        const sel = document.getElementById('saved-search-select');
        if (!sel) return;
        const current = sel.value;
        sel.innerHTML = '<option value="">— select —</option>'
            + savedSearches.map(s =>
                '<option value="' + escapeAttr(String(s.id)) + '">'
                + (s.is_default ? '★ ' : '')
                + escapeHtml(s.name)
                + '</option>'
            ).join('');
        // Restore selection when possible (after rename/delete).
        if (current && savedSearches.some(s => String(s.id) === current)) sel.value = current;
    }

    /**
     * Build the v2 saved-search payload from current map state.
     * The four categories the legacy shape missed (enabled layers, display
     * mode, base layer, map view) are top-level keys; the filter
     * accordion lives in `filters`. Viewmode (Agent/Seller) is INTENTIONALLY
     * absent — the map always loads owner-hidden regardless of saved state.
     */
    function buildSavedSearchPayload() {
        const view = (function () {
            try {
                const c = map.getCenter();
                const z = map.getZoom();
                if (c && Number.isFinite(c.lat) && Number.isFinite(c.lng) && Number.isFinite(z)) {
                    return { lat: +c.lat.toFixed(5), lng: +c.lng.toFixed(5), zoom: z };
                }
            } catch (e) { /* map not ready */ }
            return null;
        })();
        return {
            schema_version: 2,
            filters,
            enabled_layers: Array.from(enabledLayers).sort(),
            display_mode:   displayMode,
            base_layer:     baseLayerKey,
            map_view:       view,
        };
    }

    /**
     * Apply a saved search to the live map. Supports BOTH the legacy
     * payload shape (raw FILTER_DEFAULTS object at the top level) AND the
     * v2 wrapped shape ({filters, enabled_layers, display_mode, base_layer,
     * map_view, schema_version}). Robustness: every stale value is
     * defensively skipped — an old saved row never crashes the page.
     */
    function applySavedSearch(s) {
        const p = s.filter_payload || {};
        const isV2 = p && typeof p === 'object' && (p.schema_version === 2 || p.filters);

        // ── 1. Filters (the accordion shape) ──
        const filtersIn = isV2 ? (p.filters || {}) : p;
        const merged = { ...FILTER_DEFAULTS, types: [...FILTER_DEFAULTS.types], listingStatus: [] };
        Object.keys(merged).forEach(k => {
            if (Object.prototype.hasOwnProperty.call(filtersIn, k) && filtersIn[k] !== undefined) {
                merged[k] = filtersIn[k];
            }
        });
        if (!SCOPE_IS_OWNER && merged.scope === 'all') merged.scope = 'agency';
        filters = merged;
        persistFilters();
        syncFilterUi();

        // The remaining four categories only exist on v2 payloads. Legacy
        // rows skip these blocks (they kept layers/mode/base/view at their
        // current values — same as before the fix).

        // ── 2. Enabled layers ──
        if (isV2 && Array.isArray(p.enabled_layers)) {
            const requested = p.enabled_layers.filter(k => ALL_LAYER_KEYS.includes(k));
            // Empty list could be a "user disabled everything" saved state —
            // honour it, but never persist a corruption where the saved set
            // includes keys that no longer exist. Defensive on read only.
            enabledLayers.clear();
            requested.forEach(k => enabledLayers.add(k));
            // Mirror to the DOM toggle buttons.
            document.querySelectorAll('[data-layer-toggle]').forEach(btn => {
                const on = enabledLayers.has(btn.dataset.layerToggle);
                btn.dataset.on = on ? '1' : '0';
                paintLayerBtn(btn);
            });
        }

        // ── 3. Display mode (Pins / Heatmap / Both) ──
        if (isV2 && typeof p.display_mode === 'string'
            && ['pins', 'heatmap', 'both'].includes(p.display_mode)) {
            displayMode = p.display_mode;
            localStorage.setItem('corex.map.display_mode', displayMode);
            document.querySelectorAll('#display-mode-group input[type="radio"]').forEach(r => {
                r.checked = (r.value === displayMode);
            });
        }

        // ── 4. Base layer (Streets / Satellite) ──
        if (isV2 && typeof p.base_layer === 'string'
            && Object.prototype.hasOwnProperty.call(baseLayers, p.base_layer)
            && p.base_layer !== baseLayerKey) {
            map.removeLayer(baseLayers[baseLayerKey]);
            baseLayers[p.base_layer].addTo(map);
            baseLayerKey = p.base_layer;
            localStorage.setItem('corex.map.base_layer', baseLayerKey);
            // Style refresh on the basemap pills.
            document.querySelectorAll('#base-layer-toggle .base-pill').forEach(b => {
                const active = b.dataset.base === baseLayerKey;
                b.classList.toggle('active', active);
                b.style.background = active ? 'var(--brand-button)' : 'transparent';
                b.style.color = active ? '#fff' : 'var(--text-secondary)';
            });
        }

        // ── 5. Map view (lat / lng / zoom) ──
        if (isV2 && p.map_view && typeof p.map_view === 'object') {
            const v = p.map_view;
            const lat = Number(v.lat), lng = Number(v.lng), z = Number(v.zoom);
            if (Number.isFinite(lat) && Number.isFinite(lng) && Number.isFinite(z)
                && lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180
                && z >= 1 && z <= 22) {
                map.setView([lat, lng], z);
            }
        }

        // Bust the LRU cache then refetch — the new layer/displayMode/base
        // state changes the server response shape; the new map view bbox
        // requires a fresh fetch anyway.
        cache.length = 0;
        fetchPins();
    }

    document.getElementById('saved-search-select')?.addEventListener('change', e => {
        const id = e.target.value;
        if (!id) return;
        const s = savedSearches.find(x => String(x.id) === id);
        if (s) applySavedSearch(s);
    });

    document.getElementById('saved-search-default-btn')?.addEventListener('click', async () => {
        const sel = document.getElementById('saved-search-select');
        const id = sel.value;
        if (!id) { toastInfo('Pick a saved search first.'); return; }
        const url = SAVED_SEARCH_UPDATE_TPL.replace('__ID__', id);
        try {
            const resp = await csrfFetch(url, { method: 'PATCH', body: JSON.stringify({ is_default: true }) });
            if (resp.ok) { toastInfo('Default updated.'); await refreshSavedSearchList(false); }
        } catch (e) { /* swallow */ }
    });

    document.getElementById('saved-search-delete-btn')?.addEventListener('click', async () => {
        const sel = document.getElementById('saved-search-select');
        const id = sel.value;
        if (!id) { toastInfo('Pick a saved search first.'); return; }
        const s = savedSearches.find(x => String(x.id) === id);
        const name = s?.name || 'this search';
        if (!confirm('Delete "' + name + '"?')) return;
        const url = SAVED_SEARCH_DEL_TPL.replace('__ID__', id);
        try {
            const resp = await csrfFetch(url, { method: 'DELETE' });
            if (resp.ok) { toastInfo('Deleted.'); await refreshSavedSearchList(false); }
        } catch (e) { /* swallow */ }
    });

    document.getElementById('saved-search-create-btn')?.addEventListener('click', () => {
        openSavedSearchCreateModal();
    });

    function openSavedSearchCreateModal() {
        const existing = document.getElementById('map-saved-search-modal');
        if (existing) existing.remove();

        const wrap = document.createElement('div');
        wrap.id = 'map-saved-search-modal';
        wrap.style.cssText = 'position:fixed;inset:0;z-index:10000;display:flex;align-items:center;justify-content:center;background:rgba(0,0,0,0.6);padding:24px;';
        wrap.innerHTML =
            '<div style="background:var(--surface);border-radius:8px;max-width:420px;width:100%;padding:22px;box-shadow:0 10px 30px rgba(0,0,0,0.5);">'
            +   '<h3 style="font-size:1rem;font-weight:600;color:var(--text-primary);margin:0 0 8px 0;">Save current search</h3>'
            +   '<p style="font-size:0.75rem;color:var(--text-muted);margin:0 0 12px 0;">Saves the active filters under a name only you can see.</p>'
            +   '<input type="text" id="ss-name-input" maxlength="120" placeholder="e.g. Margate houses, R1.5-2.5m" '
            +     'style="width:100%;padding:7px 9px;border:1px solid var(--border);border-radius:4px;background:var(--surface-2);color:var(--text-primary);font-size:0.8125rem;box-sizing:border-box;">'
            +   '<label style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:0.75rem;cursor:pointer;">'
            +     '<input type="checkbox" id="ss-default-input"> Set as default (loads on next visit)'
            +   '</label>'
            +   '<div style="display:flex;gap:8px;justify-content:flex-end;margin-top:14px;">'
            +     '<button type="button" id="ss-cancel" style="padding:6px 14px;font-size:0.8125rem;background:transparent;border:1px solid var(--border);border-radius:4px;color:var(--text-primary);cursor:pointer;">Cancel</button>'
            +     '<button type="button" id="ss-save" disabled style="padding:6px 14px;font-size:0.8125rem;font-weight:600;background:#00d4aa;border:1px solid #00d4aa;border-radius:4px;color:#0f172a;cursor:not-allowed;opacity:0.4;">Save</button>'
            +   '</div>'
            + '</div>';
        document.body.appendChild(wrap);

        const nameEl = wrap.querySelector('#ss-name-input');
        const defEl  = wrap.querySelector('#ss-default-input');
        const save   = wrap.querySelector('#ss-save');
        const cancel = wrap.querySelector('#ss-cancel');

        nameEl.addEventListener('input', () => {
            const ok = nameEl.value.trim().length > 0;
            save.disabled = !ok;
            save.style.cursor = ok ? 'pointer' : 'not-allowed';
            save.style.opacity = ok ? '1' : '0.4';
        });
        cancel.addEventListener('click', () => wrap.remove());
        wrap.addEventListener('click', e => { if (e.target === wrap) wrap.remove(); });
        save.addEventListener('click', async () => {
            const name = nameEl.value.trim();
            if (!name) return;
            try {
                const resp = await csrfFetch(SAVED_SEARCH_STORE_URL, {
                    method: 'POST',
                    body: JSON.stringify({
                        name,
                        // v2 payload shape — wraps filters in a nested key and
                        // adds the four categories the legacy shape missed:
                        // enabled layers, display mode, base layer, map view.
                        // Viewmode (Agent/Seller) is INTENTIONALLY EXCLUDED —
                        // the map always loads in safe owner-hidden state.
                        filter_payload: buildSavedSearchPayload(),
                        is_default:     defEl.checked,
                    }),
                });
                if (resp.ok) {
                    wrap.remove();
                    toastInfo('Saved.');
                    await refreshSavedSearchList(false);
                    // Auto-select the newly created entry.
                    const created = (await resp.json()).saved_search;
                    if (created?.id) {
                        document.getElementById('saved-search-select').value = String(created.id);
                    }
                } else {
                    const body = await resp.json().catch(() => ({}));
                    toastInfo(body.error || 'Save failed.');
                }
            } catch (e) { toastInfo('Save failed.'); }
        });
        setTimeout(() => nameEl.focus(), 50);
    }

    // Phase A.3.3 — parse URL state into filters BEFORE the saved-search
    // default has a chance to run. Inside refreshSavedSearchList() we
    // check `window.location.search` again to decide whether the URL
    // already populated the state.
    if (readUrlStateIntoFilters()) {
        persistFilters();
        syncFilterUi();
    }

    // Initial load — fetch list + apply default if present (skipped when
    // the URL already drove the state).
    refreshSavedSearchList(true);

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
    // Phase B Fix 1a — sync URL on every move/zoom even when the fetch
    // is served from cache (which would otherwise skip syncUrlState).
    map.on('moveend zoomend', syncUrlState);
    // Spec §3 — toggle the scheme + price label layers as the agent
    // crosses the N₂ = 16 threshold, with no flicker (we keep the marker
    // layers populated; only their attachment to the map flips).
    map.on('zoomend', applyLabelZoomVisibility);

    // Initial render of view-mode pills + Seller banner. Always sync from
    // the resolved viewMode state (server-default is Seller; the Blade
    // statically marks the Seller pill .active to match, but a returning
    // Agent-View user with localStorage preference and the permission
    // needs the pills flipped to Agent on first paint).
    document.querySelectorAll('#view-mode-toggle .mode-pill').forEach(b => {
        const active = b.dataset.mode === viewMode;
        b.classList.toggle('active', active);
        b.style.background = active ? 'var(--brand-button)' : 'transparent';
        b.style.color = active ? '#fff' : 'var(--text-secondary)';
    });
    document.getElementById('seller-banner').style.display = (viewMode === 'seller') ? 'block' : 'none';
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
