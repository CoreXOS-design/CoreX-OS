<?php

/**
 * Commercial Market Evaluation — configurable rates, multiples, and building costs.
 *
 * All monetary values in ZAR (not cents) for readability.
 * The service converts to cents as needed.
 */
return [

    // ── Capitalisation Rates (%) ──────────────────────────────────────
    'cap_rates' => [
        'commercial'   => ['low' => 8.0,  'mid' => 9.5,  'high' => 11.0],
        'industrial'   => ['low' => 9.0,  'mid' => 10.5, 'high' => 12.0],
        'hospitality'  => ['low' => 10.0, 'mid' => 12.0, 'high' => 14.0],
        'agricultural' => ['low' => 6.0,  'mid' => 8.0,  'high' => 10.0],
    ],

    // ── Revenue Multiples (×) ─────────────────────────────────────────
    'revenue_multiples' => [
        'hospitality' => ['low' => 1.5, 'mid' => 2.5, 'high' => 3.5],
        'commercial'  => ['low' => 3.0, 'mid' => 5.0, 'high' => 7.0],
        'industrial'  => ['low' => 2.5, 'mid' => 4.0, 'high' => 6.0],
    ],

    // ── EBITDA Multiples (×) ──────────────────────────────────────────
    'ebitda_multiples' => [
        'hospitality' => ['low' => 3.0, 'mid' => 5.0, 'high' => 7.0],
    ],

    // ── Building Replacement Costs (R per m²) ─────────────────────────
    'building_costs_per_m2' => [
        'commercial_basic'    => 8000,
        'commercial_premium'  => 15000,
        'industrial_basic'    => 4500,
        'industrial_premium'  => 8000,
        'hospitality_basic'   => 12000,
        'hospitality_premium' => 25000,
        'farm_dwelling'       => 10000,
        'farm_outbuilding'    => 3000,
    ],

    // ── Depreciation Rates by Condition (%) ───────────────────────────
    'depreciation_rates' => [
        'excellent' => 0.05,
        'good'      => 0.15,
        'fair'      => 0.30,
        'poor'      => 0.50,
    ],

    // ── Agricultural Land Values (R per hectare) ──────────────────────
    'land_values_per_ha' => [
        'arable_irrigated' => ['low' => 80000,  'mid' => 150000, 'high' => 250000],
        'arable_dryland'   => ['low' => 30000,  'mid' => 60000,  'high' => 100000],
        'grazing'          => ['low' => 10000,  'mid' => 25000,  'high' => 50000],
        'bushveld_game'    => ['low' => 15000,  'mid' => 35000,  'high' => 60000],
    ],

    // ── Gross Rent Multiplier Ranges ──────────────────────────────────
    'grm_ranges' => [
        'commercial' => ['low' => 8,  'mid' => 10, 'high' => 12],
        'industrial' => ['low' => 7,  'mid' => 8.5, 'high' => 10],
    ],

    // ── Method Weights by Property Type (must sum to 100) ─────────────
    'weights' => [
        'commercial' => [
            'income_capitalisation' => 40,
            'comparable_sales'      => 30,
            'gross_rent_multiplier' => 20,
            'asset_based'           => 10,
            'revenue_multiple'      => 0,
            'productive_value'      => 0,
        ],
        'industrial' => [
            'income_capitalisation' => 40,
            'comparable_sales'      => 30,
            'gross_rent_multiplier' => 20,
            'asset_based'           => 10,
            'revenue_multiple'      => 0,
            'productive_value'      => 0,
        ],
        'hospitality' => [
            'income_capitalisation' => 30,
            'revenue_multiple'      => 30,
            'asset_based'           => 25,
            'comparable_sales'      => 15,
            'gross_rent_multiplier' => 0,
            'productive_value'      => 0,
        ],
        'agricultural' => [
            'comparable_sales'      => 30,
            'productive_value'      => 30,
            'asset_based'           => 25,
            'income_capitalisation' => 15,
            'revenue_multiple'      => 0,
            'gross_rent_multiplier' => 0,
        ],
    ],
];
