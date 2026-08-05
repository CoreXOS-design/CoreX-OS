{{--
    Phase 3g V2 Part C — reusable embedded map partial.

    Usage:
        include('corex.map.partials._embed-map', [
            'containerId'  => 'prop-map-' . $property->id,   // unique ID
            'centerLat'    => $property->latitude,
            'centerLng'    => $property->longitude,
            'radiusM'      => 1000,
            'subjectTitle' => $property->address,
            'mode'         => 'bounds',         // 'bounds' (standalone-like fetch in bounds+radius) | 'presentation' (presentation-specific endpoint)
            'presentationId' => null,           // required when mode='presentation'
            'enabledLayers' => ['sold_comps', 'active_listings'],
            'fullMapUrl'   => route('corex.map.index') . '?focus=' . ... // optional override
        ])

    Renders a 320px-tall Leaflet map with:
      - Subject pin (pulsing teal, 24px) at centerLat/centerLng
      - Sub-radius circle (default 1km)
      - Nearby pins fetched from /corex/map/pins (bounds mode) OR
        /presentations/{id}/spatial-pins (presentation mode)
      - "Open full map" link in the top-right corner

    Leaflet libs are expected on the host page (use the shared stack('head')
    push from the standalone map view, or include this partial from a page
    that already pushes them — see _embed-map-head.blade.php helper below).
--}}

@php
    $containerId    = $containerId ?? ('corex-embed-map-' . uniqid());
    $centerLat      = $centerLat   ?? null;
    $centerLng      = $centerLng   ?? null;
    $radiusM        = $radiusM     ?? 1000;
    $subjectTitle   = $subjectTitle ?? 'Subject property';
    $mode           = $mode        ?? 'bounds';
    $presentationId = $presentationId ?? null;
    $enabledLayers  = $enabledLayers ?? ['sold_comps', 'active_listings'];
    $fullMapUrl     = $fullMapUrl  ?? null;
    $hasGps         = $centerLat !== null && $centerLng !== null;
@endphp

@once
    @push('head')
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"
          integrity="sha256-p4NxAoJBhIIN+hmNHrzRCf9tD/miZyoHS5obTRR9BMY=" crossorigin="" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"
            integrity="sha256-20nQCchB9co0qIjJZRGuk2/Z9VM+kNiyxNV1lvTlZBo=" crossorigin=""></script>
    <style>
        /*
         * Hotfix 2026-05-22 — embedded Leaflet was punching through modal
         * overlays. position+z-index here creates a predictable stacking
         * context: everything inside the embed (Leaflet's panes go to 200-400,
         * controls to 1000) is contained, so a modal overlay at z >= 9999
         * always wins on the host page.
         */
        .corex-embed-map { position: relative; z-index: 1; isolation: isolate; height: 320px; border-radius: 6px; overflow: hidden; border: 1px solid var(--border, #e2e8f0); }
        .corex-embed-map .leaflet-container { background: #e5e7eb; }
        .corex-embed-subject-pulse {
            display: flex; align-items: center; justify-content: center;
            width: 24px; height: 24px;
            background: #00d4aa; color: #fff;
            border: 3px solid #fff; border-radius: 50%;
            font-size: 12px; font-weight: 700;
            box-shadow: 0 0 0 0 rgba(0, 212, 170, 0.55), 0 2px 6px rgba(0,0,0,.35);
            animation: corex-pulse 2s infinite;
        }
        @keyframes corex-pulse {
            0%   { box-shadow: 0 0 0 0 rgba(0, 212, 170, 0.55), 0 2px 6px rgba(0,0,0,.35); }
            70%  { box-shadow: 0 0 0 14px rgba(0, 212, 170, 0), 0 2px 6px rgba(0,0,0,.35); }
            100% { box-shadow: 0 0 0 0 rgba(0, 212, 170, 0), 0 2px 6px rgba(0,0,0,.35); }
        }
    </style>
    @endpush
@endonce

<div class="corex-embed-map">
    @if($hasGps)
        <div id="{{ $containerId }}" style="position: absolute; inset: 0;"></div>

        @if($fullMapUrl)
            <a href="{{ $fullMapUrl }}"
               style="position: absolute; top: 8px; right: 8px; z-index: 1000; padding: 4px 8px; font-size: 0.6875rem; font-weight: 500; color: var(--text-primary, #0f172a); background: rgba(255,255,255,0.92); border: 1px solid var(--border, #cbd5e1); border-radius: 4px; text-decoration: none; box-shadow: 0 1px 3px rgba(0,0,0,.15);">
                Open full map →
            </a>
        @endif

        <div id="{{ $containerId }}-caption"
             style="position: absolute; bottom: 8px; left: 8px; z-index: 1000; padding: 4px 8px; font-size: 0.6875rem; color: var(--text-secondary, #475569); background: rgba(255,255,255,0.92); border: 1px solid var(--border, #cbd5e1); border-radius: 4px; box-shadow: 0 1px 3px rgba(0,0,0,.10);">
            Loading…
        </div>
    @else
        <div style="position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; padding: 24px; text-align: center; background: #f1f5f9; color: var(--text-muted, #64748b); font-size: 0.875rem;">
            <div>
                <div style="font-weight: 500; color: var(--text-primary, #0f172a); margin-bottom: 4px;">No GPS for this property yet</div>
                <div style="font-size: 0.75rem;">Run <code style="background: rgba(0,0,0,.05); padding: 1px 4px; border-radius: 3px;">php artisan geocoding:backfill</code> to resolve via the waterfall.</div>
            </div>
        </div>
    @endif
</div>

@if($hasGps)
<script>
(function () {
    'use strict';
    if (typeof window.__corexEmbedMapInit === 'undefined') {
        // First time this partial runs on a page — install the shared init helper.
        window.__corexEmbedMapInit = function (opts) {
            if (typeof L === 'undefined') {
                console.error('CoreX embed-map: Leaflet not loaded');
                return;
            }

            const map = L.map(opts.containerId, {
                zoomControl: true,
                attributionControl: true,
            }).setView([opts.centerLat, opts.centerLng], 16);

            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors',
                maxZoom: 19,
            }).addTo(map);

            // Subject pin (pulsing teal).
            const subjectIcon = L.divIcon({
                html: '<div class="corex-embed-subject-pulse">★</div>',
                className: 'corex-pin-subject',
                iconSize: [24, 24], iconAnchor: [12, 12],
            });
            L.marker([opts.centerLat, opts.centerLng], { icon: subjectIcon })
                .addTo(map)
                .bindTooltip(opts.subjectTitle || 'Subject', { direction: 'top' });

            // Radius circle.
            if (opts.radiusM > 0) {
                L.circle([opts.centerLat, opts.centerLng], {
                    radius: opts.radiusM,
                    color: '#00d4aa', weight: 1.5,
                    fillColor: '#00d4aa', fillOpacity: 0.05,
                    interactive: false,
                }).addTo(map);
            }

            // Pin colours match the standalone map.
            const LAYER_COLOURS = {
                hfc_listings: '#00d4aa', sold_comps: '#3b82f6',
                active_listings: '#f59e0b', mic_subjects: '#64748b',
                scheme_owners: '#8b5cf6',
            };
            const LAYER_LETTERS = {
                hfc_listings: 'H', sold_comps: 'S', active_listings: 'A',
                mic_subjects: 'M', scheme_owners: 'O',
            };
            function nearbyIcon(layer) {
                const colour = LAYER_COLOURS[layer] || '#64748b';
                const letter = LAYER_LETTERS[layer] || '?';
                return L.divIcon({
                    html: '<div style="display:flex;align-items:center;justify-content:center;width:20px;height:20px;background:'
                        + colour + ';color:#fff;border:2px solid #fff;border-radius:50%;font-size:10px;font-weight:700;box-shadow:0 1px 2px rgba(0,0,0,.4);">'
                        + letter + '</div>',
                    className: 'corex-pin', iconSize: [20, 20], iconAnchor: [10, 10],
                });
            }

            const captionEl = document.getElementById(opts.containerId + '-caption');

            // Fetch nearby pins. Two modes:
            //   bounds       — /corex/map/pins?radiusCenter*+radiusM+bounds
            //   presentation — /presentations/{id}/spatial-pins (no bounds)
            const fetchUrl = opts.mode === 'presentation'
                ? opts.presentationPinsUrl
                : buildBoundsUrl(opts);

            fetch(fetchUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
                .then(r => r.ok ? r.json() : Promise.reject(r.status))
                .then(payload => renderEmbed(payload, opts))
                .catch(err => {
                    console.warn('CoreX embed-map: pin fetch failed', err);
                    if (captionEl) captionEl.textContent = 'Could not load nearby pins.';
                });

            function buildBoundsUrl(opts) {
                // ~1.5x radius coarse bounding box around centre. Server's radius
                // post-filter does the precise circle.
                const deg = (opts.radiusM * 1.5) / 111320;
                const lngDeg = deg / Math.cos(opts.centerLat * Math.PI / 180);
                const params = new URLSearchParams({
                    north: (opts.centerLat + deg).toFixed(6),
                    south: (opts.centerLat - deg).toFixed(6),
                    east:  (opts.centerLng + lngDeg).toFixed(6),
                    west:  (opts.centerLng - lngDeg).toFixed(6),
                    radiusCenterLat: opts.centerLat.toFixed(6),
                    radiusCenterLng: opts.centerLng.toFixed(6),
                    radiusM: String(opts.radiusM),
                    limit: '500',
                    include_demo: opts.includeDemo ? '1' : '0',
                });
                (opts.enabledLayers || []).forEach(l => params.append('layers[]', l));
                return opts.pinsUrl + '?' + params.toString();
            }

            function renderEmbed(payload, opts) {
                let soldN = 0, activeN = 0, totalN = 0;
                const addPin = (pin) => {
                    const m = L.marker([pin.lat, pin.lng], { icon: nearbyIcon(pin.layer) });
                    const html = '<div style="font-weight:600;font-size:0.8125rem;">' + (pin.title || '') + '</div>'
                        + '<div style="font-size:0.75rem;color:#64748b;margin-top:2px;">' + (pin.subtitle || '') + '</div>'
                        + (opts.fullMapUrl ? '<div style="margin-top:6px;"><a href="' + opts.fullMapUrl + '" style="font-size:0.6875rem;color:#0ea5e9;">View detail →</a></div>' : '');
                    m.bindPopup(html, { maxWidth: 220 });
                    m.addTo(map);
                    totalN++;
                    if (pin.layer === 'sold_comps') soldN++;
                    else if (pin.layer === 'active_listings') activeN++;
                };

                if (opts.mode === 'presentation') {
                    (payload.sold_comps      || []).forEach(addPin);
                    (payload.active_listings || []).forEach(addPin);
                    if (captionEl) captionEl.textContent = soldN + ' sold comp' + (soldN === 1 ? '' : 's') + ' and ' + activeN + ' active listing' + (activeN === 1 ? '' : 's') + ' — backing this analysis';
                } else {
                    (payload.layers || []).forEach(layer => (layer.pins || []).forEach(addPin));
                    if (captionEl) captionEl.textContent = 'Showing ' + soldN + ' sold comp' + (soldN === 1 ? '' : 's') + ' and ' + activeN + ' active listing' + (activeN === 1 ? '' : 's') + ' within ' + (opts.radiusM / 1000).toFixed(1) + ' km';
                }
            }
        };
    }

    document.addEventListener('DOMContentLoaded', function () {
        window.__corexEmbedMapInit({
            containerId:  @json($containerId),
            centerLat:    {{ (float) $centerLat }},
            centerLng:    {{ (float) $centerLng }},
            radiusM:      {{ (int) $radiusM }},
            subjectTitle: @json($subjectTitle),
            mode:         @json($mode),
            enabledLayers: @json($enabledLayers),
            includeDemo:  {{ app()->environment('production') ? 'false' : 'true' }},
            pinsUrl:              @json(route('corex.map.pins')),
            presentationPinsUrl:  {!! $presentationId ? "'" . route('presentations.spatial-pins', $presentationId) . "'" : 'null' !!},
            fullMapUrl:   @json($fullMapUrl ?? ''),
        });
    });
})();
</script>
@endif
