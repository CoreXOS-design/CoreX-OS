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
    'kzn_south_coast' => [
        'name'  => 'KZN South Coast',
        'towns' => [
            ['name' => 'Margate',         'suburbs' => ['Margate', 'Uvongo', 'Manaba Beach', 'Ramsgate']],
            ['name' => 'Shelly Beach',    'suburbs' => ['Shelly Beach', 'St Michaels-on-Sea', 'Southbroom']],
            ['name' => 'Port Shepstone',  'suburbs' => ['Port Shepstone', 'Oslo Beach', 'Umtentweni']],
            ['name' => 'Hibberdene',      'suburbs' => ['Hibberdene']],
            ['name' => 'Pumula',          'suburbs' => ['Pumula']],
            ['name' => 'Munster',         'suburbs' => ['Munster']],
            ['name' => 'Trafalgar',       'suburbs' => ['Trafalgar']],
            ['name' => 'Palm Beach',      'suburbs' => ['Palm Beach', 'Marina Beach']],
        ],
    ],

    'kzn_north_coast' => [
        'name'  => 'KZN North Coast',
        'towns' => [
            ['name' => 'Ballito',  'suburbs' => ['Ballito', 'Salt Rock', 'Sheffield Beach', 'Simbithi']],
            ['name' => 'Umhlanga', 'suburbs' => ['Umhlanga Rocks', 'Umhlanga Ridge', 'La Lucia']],
            ['name' => 'Tongaat',  'suburbs' => ['Tongaat', 'Tongaat Beach']],
            ['name' => 'Stanger',  'suburbs' => ['Stanger', 'KwaDukuza']],
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
