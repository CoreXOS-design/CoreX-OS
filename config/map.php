<?php

/**
 * Map module — system-wide default config.
 *
 * These are the FALLBACK defaults. Per-agency overrides live in
 * `agency_map_settings` (App\Models\AgencyMapSettings), which seeds from these
 * values and is read by MapController + MapBoundsRequest. Sensible defaults =
 * Home Finders Coastal's current KZN South Coast values; every other tenant
 * gets these only until they set their own (no more hardcoded HFC box for all).
 */
return [
    'defaults' => [
        // Initial map view when no ?lat/lng/z is supplied.
        'center' => [
            'lat' => (float) env('MAP_DEFAULT_CENTER_LAT', -30.70),
            'lng' => (float) env('MAP_DEFAULT_CENTER_LNG', 30.45),
        ],
        'zoom' => (int) env('MAP_DEFAULT_ZOOM', 11),

        // The bounding box the map fits to on load / "Reset to area" (was the
        // hardcoded HFC_BOUNDS in the blade — KZN South Coast).
        'bounds' => [
            'north' => (float) env('MAP_BOUNDS_NORTH', -30.40),
            'south' => (float) env('MAP_BOUNDS_SOUTH', -31.00),
            'east'  => (float) env('MAP_BOUNDS_EAST',  30.90),
            'west'  => (float) env('MAP_BOUNDS_WEST',  30.00),
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
