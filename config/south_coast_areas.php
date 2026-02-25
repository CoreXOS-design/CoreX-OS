<?php

/**
 * South Coast Suburb-to-Town Mapping
 *
 * Maps parent towns/cities to their constituent suburbs.
 * Used by ArticleMatcherService, PropConInsightsService, and AbsorptionInflowService
 * to broaden search areas from suburb-level to town-level.
 *
 * Given a suburb, find its town. Given a town, find all suburbs in it.
 */
return [

    'towns' => [
        'Margate' => [
            'Manaba Beach', 'Uvongo', 'Uvongo Beach', 'Shelly Beach',
            'Marina Beach', 'Ramsgate', 'Southbroom', 'San Lameer',
            'Trafalgar', 'Leisure Bay', 'Margate', 'Lucien Beach',
        ],
        'Port Shepstone' => [
            'Port Shepstone', 'Munster', 'Oslo Beach', 'Pumula',
            'Shelly Centre', 'Sea Park',
        ],
        'Port Edward' => [
            'Port Edward', 'Leisure Crest', 'Glenmore Beach',
        ],
        'Scottburgh' => [
            'Scottburgh', 'Pennington', 'Park Rynie', 'Kelso',
            'Clansthal', 'Sezela',
        ],
        'Hibberdene' => [
            'Hibberdene', 'Umzumbe', 'Sunwich Port', 'Bendigo',
            'Ifafa Beach', 'Elysium',
        ],
        'Ballito' => [
            'Ballito', 'Salt Rock', 'Sheffield Beach', 'Tinley Manor',
            'Zimbali', 'Simbithi',
        ],
        'Umhlanga' => [
            'Umhlanga', 'Umhlanga Rocks', 'Umhlanga Ridge',
            'La Lucia', 'La Lucia Ridge', 'Gateway',
        ],
        'Durban' => [
            'Durban', 'Durban North', 'Durban Central',
            'Berea', 'Morningside', 'Musgrave',
        ],
        'Amanzimtoti' => [
            'Amanzimtoti', 'Warner Beach', 'Winklespruit',
            'Illovo Beach', 'Kingsburgh', 'Doonside',
        ],
    ],

    /**
     * Region-level keywords used for article matching.
     * Articles mentioning these terms are relevant to all South Coast presentations.
     */
    'regions' => [
        'South Coast', 'KZN', 'KwaZulu-Natal', 'Hibiscus Coast',
        'Ugu', 'Ray Nkonyeni', 'Lower South Coast', 'KZN South Coast',
    ],

];
