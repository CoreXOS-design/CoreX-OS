<?php

/**
 * Curated South African real-estate region → towns → suburbs library.
 *
 * Consumed by App\Services\Prospecting\RegionSuggestionService and surfaced
 * via the "Build from suggested regions" UI on the Prospecting Setup page.
 *
 * Adding a new region: add an entry below with a snake_case key. Each town
 * needs `name` + `suburbs` (array of strings). Library is reference data,
 * not user data — agencies edit their own copies after importing.
 *
 * Spec: .ai/specs/prospecting-setup-spec.md S4, Section 12 Open Question #1.
 */

return [
    // ── KZN South Coast taxonomy (Johan-ruled 2026-07-13, verified on P24) ──
    // Rule: where a P24 alias region exists → use it verbatim; where none → the
    // MUNICIPAL name grouping the P24 towns beneath it. All agency-editable in
    // the Regions screen; the suburb queue catches the long tail.

    // P24 ALIAS REGION (verified on-site: Hibiscus Coast, published member towns).
    'hibiscus_coast' => [
        'name'  => 'Hibiscus Coast',
        'towns' => [
            ['name' => 'Margate',        'suburbs' => ['Margate', 'Uvongo', 'Manaba Beach', 'Ramsgate', 'Shelly Beach', 'St Michaels-on-Sea']],
            ['name' => 'Port Shepstone', 'suburbs' => ['Port Shepstone', 'Oslo Beach', 'Umtentweni', 'Sea Park', 'Marburg']],
            ['name' => 'Southbroom',     'suburbs' => ['Southbroom']],
            ['name' => 'Port Edward',    'suburbs' => ['Port Edward', 'Munster', 'Palm Beach', 'Glenmore Beach']],
            ['name' => 'Marina Beach',   'suburbs' => ['Marina Beach', 'San Lameer']],
            ['name' => 'Umzumbe',        'suburbs' => ['Umzumbe', 'Pumula', 'Sunwich Port']],
            ['name' => 'Hibberdene',     'suburbs' => ['Hibberdene']],
            ['name' => 'Trafalgar',      'suburbs' => ['Trafalgar']],
        ],
    ],

    // MUNICIPAL region — P24 has NO north alias (Ballito is a top-level area);
    // KwaDukuza is the municipality grouping Ballito + surrounds.
    'kwadukuza' => [
        'name'  => 'KwaDukuza',
        'towns' => [
            ['name' => 'Ballito',   'suburbs' => ['Ballito', 'Salt Rock', 'Sheffield Beach', 'Shakas Rock', 'Simbithi', 'Umhlali', 'Tinley Manor']],
            ['name' => 'KwaDukuza', 'suburbs' => ['KwaDukuza', 'Stanger', 'Shakaskraal']],
        ],
    ],

    // MUNICIPAL region — Scottburgh area (Johan's call: Umdoni municipality).
    'umdoni' => [
        'name'  => 'Umdoni',
        'towns' => [
            ['name' => 'Scottburgh', 'suburbs' => ['Scottburgh', 'Scottburgh South', 'Kelso', 'Pennington', 'Park Rynie', 'Sezela']],
            ['name' => 'Umkomaas',   'suburbs' => ['Umkomaas', 'Widenham', 'Craigieburn']],
        ],
    ],

    'durban_central' => [
        'name'  => 'Durban Central',
        'towns' => [
            ['name' => 'Durban',     'suburbs' => ['Durban Central', 'Berea', 'Morningside', 'Glenwood', 'Musgrave']],
            ['name' => 'Westville',  'suburbs' => ['Westville', 'Westville North']],
            ['name' => 'Pinetown',   'suburbs' => ['Pinetown', 'New Germany', 'Cowies Hill']],
            ['name' => 'Hillcrest',  'suburbs' => ['Hillcrest', 'Kloof', 'Gillitts']],
        ],
    ],

    'cape_town_atlantic_seaboard' => [
        'name'  => 'Cape Town Atlantic Seaboard',
        'towns' => [
            ['name' => 'Sea Point',           'suburbs' => ['Sea Point', 'Three Anchor Bay', 'Bantry Bay', 'Fresnaye']],
            ['name' => 'Clifton & Camps Bay', 'suburbs' => ['Clifton', 'Camps Bay', 'Bakoven']],
            ['name' => 'Mouille Point',       'suburbs' => ['Mouille Point', 'Green Point', 'V&A Waterfront']],
        ],
    ],

    'cape_town_southern_suburbs' => [
        'name'  => 'Cape Town Southern Suburbs',
        'towns' => [
            ['name' => 'Constantia', 'suburbs' => ['Constantia', 'Constantia Upper', 'Bishopscourt']],
            ['name' => 'Claremont',  'suburbs' => ['Claremont', 'Newlands', 'Rondebosch', 'Rosebank']],
            ['name' => 'Wynberg',    'suburbs' => ['Wynberg', 'Plumstead', 'Diep River']],
        ],
    ],

    'jhb_sandton_north' => [
        'name'  => 'Johannesburg — Sandton & North',
        'towns' => [
            ['name' => 'Sandton',   'suburbs' => ['Sandton Central', 'Sandhurst', 'Hyde Park', 'Atholl', 'Inanda']],
            ['name' => 'Bryanston', 'suburbs' => ['Bryanston', 'Magaliessig', 'Petervale']],
            ['name' => 'Fourways',  'suburbs' => ['Fourways', 'Lonehill', 'Dainfern', 'Cedar Lakes']],
            ['name' => 'Randburg',  'suburbs' => ['Randburg', 'Ferndale', 'Bordeaux', 'Blairgowrie']],
        ],
    ],

    'pretoria_east' => [
        'name'  => 'Pretoria East',
        'towns' => [
            ['name' => 'Menlo Park',   'suburbs' => ['Menlo Park', 'Brooklyn', 'Waterkloof', 'Waterkloof Ridge']],
            ['name' => 'Lynnwood',     'suburbs' => ['Lynnwood', 'Lynnwood Glen', 'Lynnwood Ridge', 'Faerie Glen']],
            ['name' => 'Silver Lakes', 'suburbs' => ['Silver Lakes', 'Olympus', 'Mooikloof']],
        ],
    ],

    'garden_route' => [
        'name'  => 'Garden Route',
        'towns' => [
            ['name' => 'George',          'suburbs' => ['George Central', 'Heatherlands', 'Glenwood', 'Heather Park']],
            ['name' => 'Knysna',          'suburbs' => ['Knysna Central', 'Thesen Islands', 'Brenton on Sea', 'Belvidere']],
            ['name' => 'Plettenberg Bay', 'suburbs' => ['Plettenberg Bay', 'Keurboomstrand', 'Beachy Head']],
            ['name' => 'Mossel Bay',      'suburbs' => ['Mossel Bay', 'Hartenbos', 'Reebok']],
        ],
    ],
];
