<?php

/**
 * Map module — system-wide default config.
 *
 * These are the FALLBACK defaults, and they are NEUTRAL: the whole of South Africa. Per-agency overrides live in
 * `agency_map_settings` (App\Models\AgencyMapSettings), which is read by MapController + MapBoundsRequest. An agency that has set
 * nothing opens on the area its OWN properties are in (AgencyMapSettings::center()/bounds() derive it from their coordinates), and
 * only when it has none yet on this country view. The map used to open on one agency's patch (the KZN South Coast) for everybody.
 */
return [
    'defaults' => [
        // Initial map view when no ?lat/lng/z is supplied (no agency data to derive one from): South Africa.
        'center' => [
            'lat' => (float) env('MAP_DEFAULT_CENTER_LAT', -28.50),
            'lng' => (float) env('MAP_DEFAULT_CENTER_LNG', 24.70),
        ],
        'zoom' => (int) env('MAP_DEFAULT_ZOOM', 5),

        // The bounding box the map fits to on load / "Reset to area": South Africa.
        'bounds' => [
            'north' => (float) env('MAP_BOUNDS_NORTH', -22.00),
            'south' => (float) env('MAP_BOUNDS_SOUTH', -35.00),
            'east'  => (float) env('MAP_BOUNDS_EAST',  33.00),
            'west'  => (float) env('MAP_BOUNDS_WEST',  16.00),
        ],

        // Default sold-date window for the agency Sold layer (3mo|6mo|12mo|24mo|all).
        'sold_window' => env('MAP_DEFAULT_SOLD_WINDOW', '6mo'),

        // Per-layer pin caps (moved out of MapBoundsRequest hardcoding).
        'caps' => [
            'max_limit'           => (int) env('MAP_CAP_MAX_LIMIT', 5000),
            'default_limit'       => (int) env('MAP_CAP_DEFAULT_LIMIT', 2000),
            'min_per_layer'       => (int) env('MAP_CAP_MIN_PER_LAYER', 50),
            'region_cap'          => (int) env('MAP_CAP_REGION', 200),  // span >= 0.5 deg
            'town_cap'            => (int) env('MAP_CAP_TOWN', 500),     // span >= 0.05 deg
            'dense_layer_floor'   => (int) env('MAP_CAP_DENSE_FLOOR', 1000),
            'dense_layer_ceiling' => (int) env('MAP_CAP_DENSE_CEILING', 1500),
        ],
    ],
];
