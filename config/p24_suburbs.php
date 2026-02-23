<?php

/**
 * Property24 suburb lookup — KZN South Coast (HF Coastal operating area).
 *
 * Each entry maps a normalised key (lowercase, spaces or hyphens) to:
 *   - id:          P24's numeric suburb identifier (confirmed IDs noted)
 *   - slug:        URL path segment used on property24.com
 *   - surrounding: array of nearby suburb IDs to include in "wider area" searches
 *
 * Confirmed IDs: 6357 (Shelly Beach), 6358 (St Michaels), 6359 (Uvongo),
 *                6336 (Oslo Beach), 33106 (Uvongo Beach), 6361 (Manaba Beach)
 * Others follow P24 pattern — verify when possible.
 */

return [
    'shelly beach'          => ['id' => 6357,  'slug' => 'shelly-beach',        'surrounding' => [6358, 33106, 6336]],
    'shelly-beach'          => ['id' => 6357,  'slug' => 'shelly-beach',        'surrounding' => [6358, 33106, 6336]],
    'shelley beach'         => ['id' => 6357,  'slug' => 'shelly-beach',        'surrounding' => [6358, 33106, 6336]],
    'uvongo'                => ['id' => 6359,  'slug' => 'uvongo',              'surrounding' => [33106, 6361]],
    'margate'               => ['id' => 6348,  'slug' => 'margate',             'surrounding' => []],
    'ramsgate'              => ['id' => 6354,  'slug' => 'ramsgate',            'surrounding' => []],
    'southbroom'            => ['id' => 6360,  'slug' => 'southbroom',          'surrounding' => []],
    'manaba beach'          => ['id' => 6361,  'slug' => 'manaba-beach',        'surrounding' => []],
    'manaba-beach'          => ['id' => 6361,  'slug' => 'manaba-beach',        'surrounding' => []],
    'port shepstone'        => ['id' => 6353,  'slug' => 'port-shepstone',      'surrounding' => []],
    'port-shepstone'        => ['id' => 6353,  'slug' => 'port-shepstone',      'surrounding' => []],
    'st michaels on sea'    => ['id' => 6358,  'slug' => 'st-michaels-on-sea',  'surrounding' => [6357, 33106]],
    'st-michaels-on-sea'    => ['id' => 6358,  'slug' => 'st-michaels-on-sea',  'surrounding' => [6357, 33106]],
    'oslo beach'            => ['id' => 6336,  'slug' => 'oslo-beach',          'surrounding' => [6357]],
    'oslo-beach'            => ['id' => 6336,  'slug' => 'oslo-beach',          'surrounding' => [6357]],
    'uvongo beach'          => ['id' => 33106, 'slug' => 'uvongo-beach',        'surrounding' => [6359, 6358]],
    'uvongo-beach'          => ['id' => 33106, 'slug' => 'uvongo-beach',        'surrounding' => [6359, 6358]],
    'hibberdene'            => ['id' => 6342,  'slug' => 'hibberdene',          'surrounding' => []],
    'port edward'           => ['id' => 6352,  'slug' => 'port-edward',         'surrounding' => []],
    'port-edward'           => ['id' => 6352,  'slug' => 'port-edward',         'surrounding' => []],
    'palm beach'            => ['id' => 6351,  'slug' => 'palm-beach',          'surrounding' => []],
    'palm-beach'            => ['id' => 6351,  'slug' => 'palm-beach',          'surrounding' => []],
    'marina beach'          => ['id' => 6349,  'slug' => 'marina-beach',        'surrounding' => []],
    'marina-beach'          => ['id' => 6349,  'slug' => 'marina-beach',        'surrounding' => []],
    'trafalgar'             => ['id' => 6363,  'slug' => 'trafalgar',           'surrounding' => []],
    'san lameer'            => ['id' => 6356,  'slug' => 'san-lameer',          'surrounding' => []],
    'san-lameer'            => ['id' => 6356,  'slug' => 'san-lameer',          'surrounding' => []],
    'leisure bay'           => ['id' => 6345,  'slug' => 'leisure-bay',         'surrounding' => []],
    'leisure-bay'           => ['id' => 6345,  'slug' => 'leisure-bay',         'surrounding' => []],
    'munster'               => ['id' => 6350,  'slug' => 'munster',             'surrounding' => []],
    'sea park'              => ['id' => 11529, 'slug' => 'sea-park',            'surrounding' => []],
    'sea-park'              => ['id' => 11529, 'slug' => 'sea-park',            'surrounding' => []],
];
