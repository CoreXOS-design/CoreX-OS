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
                        ['key' => 'scheme_owners',   'label' => 'Scheme Owners',   'colour' => '#8b5cf6', 'letter' => 'O', 'sensitive' => true],
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
        </main>

        {{-- Right detail panel --}}
        <aside id="map-detail-panel"
               style="width: 360px; flex-shrink: 0; background: var(--surface); border-left: 1px solid var(--border); transform: translateX(100%); transition: transform 220ms ease; overflow-y: auto; position: relative;">
            <div style="display: flex; align-items: flex-start; justify-content: space-between; padding: 14px 16px; border-bottom: 1px solid var(--border); position: sticky; top: 0; background: var(--surface); z-index: 1;">
                <div style="flex: 1; min-width: 0;">
                    <div id="detail-title" style="font-size: 0.9375rem; font-weight: 600; color: var(--text-primary); overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="detail-subtitle" style="font-size: 0.75rem; color: var(--text-muted); margin-top: 2px;"></div>
                </div>
                <button id="detail-close-btn" aria-label="Close panel" style="margin-left: 8px; padding: 4px; background: transparent; border: 0; color: var(--text-muted); cursor: pointer; font-size: 1rem; line-height: 1;">×</button>
            </div>
            <div style="padding: 14px 16px;">
                <div id="detail-address" style="font-size: 0.75rem; color: var(--text-secondary); margin-bottom: 12px; display: none;"></div>
                <div id="detail-facts"></div>
                <div id="detail-sensitive" style="display: none; margin-top: 14px; padding: 10px; background: color-mix(in srgb, var(--ds-purple, #8b5cf6) 8%, transparent); border-left: 3px solid var(--ds-purple, #8b5cf6); border-radius: 4px;">
                    <div style="font-size: 0.625rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; color: var(--ds-purple, #8b5cf6); margin-bottom: 6px;">Agent only</div>
                    <div id="detail-sensitive-facts"></div>
                </div>
                <div id="detail-relationships" style="margin-top: 14px;"></div>
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

    // ── State ─────────────────────────────────────────────────────────────
    let viewMode = localStorage.getItem('corex.map.view_mode') || 'agent';
    let baseLayerKey = localStorage.getItem('corex.map.base_layer') || 'streets';
    const enabledLayers = new Set([
        'hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners',
    ]);
    const clusterByLayer = {}; // key → L.markerClusterGroup
    const cache = []; // [{ key, payload }]
    let fetchTimer = null;
    let inFlight = null;

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

    // Pre-create one cluster group per layer so toggles act independently.
    ['hfc_listings', 'sold_comps', 'active_listings', 'mic_subjects', 'scheme_owners'].forEach(k => {
        clusterByLayer[k] = L.markerClusterGroup({
            disableClusteringAtZoom: 14,
            maxClusterRadius: 50,
            chunkedLoading: true,
        }).addTo(map);
    });

    // ── Helpers ───────────────────────────────────────────────────────────
    function pinIcon(layer) {
        const colour = LAYER_COLOURS[layer] || '#64748b';
        const letter = LAYER_LETTERS[layer] || '?';
        const html = '<div style="display:flex;align-items:center;justify-content:center;width:22px;height:22px;background:' + colour
            + ';color:#fff;border:2px solid #fff;border-radius:50%;font-size:11px;font-weight:700;box-shadow:0 1px 3px rgba(0,0,0,.4);">'
            + letter + '</div>';
        return L.divIcon({ html: html, className: 'corex-pin', iconSize: [22, 22], iconAnchor: [11, 11] });
    }

    function currentBounds() {
        const b = map.getBounds();
        return {
            north: b.getNorth(), south: b.getSouth(),
            east: b.getEast(),   west: b.getWest(),
        };
    }

    function boundsKey(b) {
        return [b.south, b.west, b.north, b.east, viewMode, Array.from(enabledLayers).sort().join(',')]
            .map(v => typeof v === 'number' ? v.toFixed(4) : v).join('|');
    }

    function setLoading(on) {
        document.getElementById('map-loading-pill').style.display = on ? 'inline-flex' : 'none';
    }

    function clearAllPins() {
        Object.values(clusterByLayer).forEach(c => c.clearLayers());
    }

    function renderPayload(payload) {
        clearAllPins();
        let total = 0;
        let cappedLayers = [];

        (payload.layers || []).forEach(layer => {
            const cluster = clusterByLayer[layer.key];
            if (!cluster) return;

            // Update count badge
            const badge = document.querySelector('[data-layer-count="' + layer.key + '"]');
            if (badge) {
                badge.textContent = layer.capped
                    ? layer.count + ' / ' + layer.total
                    : String(layer.count);
            }
            if (layer.capped) cappedLayers.push(layer.key + ' (' + layer.count + '/' + layer.total + ')');

            // Skip rendering if layer disabled via checkbox
            if (!enabledLayers.has(layer.key)) return;

            (layer.pins || []).forEach(pin => {
                const m = L.marker([pin.lat, pin.lng], { icon: pinIcon(layer.key) });
                m.bindTooltip(pin.title + '\n' + (pin.subtitle || ''), { direction: 'top' });
                m.on('click', () => openDetail(pin));
                cluster.addLayer(m);
            });
            total += layer.pins.length;
        });

        document.getElementById('empty-state').style.display = total === 0 ? 'block' : 'none';

        const cap = document.getElementById('layer-cap-notice');
        if (cappedLayers.length > 0) {
            cap.textContent = 'Some layers truncated: ' + cappedLayers.join(', ');
            cap.style.display = 'block';
        } else {
            cap.style.display = 'none';
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
        });
        // Always send all five layer keys so server gives counts for each;
        // the UI hides disabled ones at render time.
        ['hfc_listings','sold_comps','active_listings','mic_subjects','scheme_owners'].forEach(k => params.append('layers[]', k));

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

    // ── Detail panel ──────────────────────────────────────────────────────
    const detailPanel = document.getElementById('map-detail-panel');
    document.getElementById('detail-close-btn').addEventListener('click', () => {
        detailPanel.style.transform = 'translateX(100%)';
    });

    async function openDetail(pin) {
        document.getElementById('detail-title').textContent = pin.title || '';
        document.getElementById('detail-subtitle').textContent = pin.subtitle || '';
        document.getElementById('detail-facts').innerHTML = '<div style="font-size:0.75rem;color:var(--text-muted);">Loading…</div>';
        document.getElementById('detail-relationships').innerHTML = '';
        document.getElementById('detail-sensitive').style.display = 'none';
        document.getElementById('detail-address').style.display = 'none';
        detailPanel.style.transform = 'translateX(0)';

        try {
            const url = pin.detail_url + (pin.detail_url.includes('?') ? '&' : '?') + 'viewMode=' + viewMode;
            const resp = await fetch(url, { headers: { 'Accept': 'application/json' }, credentials: 'same-origin' });
            if (!resp.ok) {
                document.getElementById('detail-facts').innerHTML = '<div style="color:var(--ds-red,#dc2626);font-size:0.75rem;">Could not load details.</div>';
                return;
            }
            const card = await resp.json();
            renderCard(card);
        } catch (e) {
            document.getElementById('detail-facts').innerHTML = '<div style="color:var(--ds-red,#dc2626);font-size:0.75rem;">' + (e.message || 'Error') + '</div>';
        }
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
            // Re-render without re-fetching — payload is cached.
            const hit = cache[cache.length - 1];
            if (hit) renderPayload(hit.payload);
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
