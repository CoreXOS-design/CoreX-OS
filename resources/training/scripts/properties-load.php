<?php

/**
 * Training script - Properties 1 of 4: load a new property with the New Property wizard. Selectors walked on QA1 as the training agent. The wizard keeps a draft, so the
 * recording only points at the fields and never types or continues: nothing is created.
 */
return [
    'key' => 'properties-load',
    'title' => 'Load a new property',
    'route' => 'corex.properties.wizard',
    'url' => '/corex/properties/wizard',
    'specs' => ['.ai/specs/property-upload-wizard.md'],
    'audience' => [],
    'related' => ['properties-edit', 'properties-photos', 'properties-status'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to load a new property in four quick steps.',
    'steps' => [
        [
            'selector' => '[data-tour="wiz-rail"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'There are four steps. You can save as a draft at any point.',
            'why' => 'You can start at the mandate signing and finish later.',
        ],
        [
            'selector' => '[data-tour="wiz-listing-type"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose For Sale or For Rental.',
            'why' => 'It decides which fields and which portals the property uses.',
        ],
        [
            'selector' => '[data-tour="wiz-headline"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Write a short headline, like Family home in Uvongo.',
            'why' => 'Buyers read this first on the portals and on your website.',
        ],
        [
            'selector' => '[data-tour="wiz-price"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add the asking price.',
            'why' => 'It feeds your buyer matches, so the right buyers hear about it.',
        ],
        [
            'selector' => '[data-tour="wiz-street"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add the street address and the suburb.',
            'why' => 'CoreX checks it against what it already knows, so nothing is loaded twice.',
        ],
        [
            'selector' => '[data-tour="wiz-rooms"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add the bedrooms, bathrooms and garages.',
            'why' => 'Buyers filter on these, so a missing number loses you enquiries.',
        ],
        [
            'selector' => '[data-tour="wiz-continue"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Continue to add your photos and description.',
            'why' => 'The property is saved as a draft until you publish it.',
        ],
    ],
    'outro' => 'Next, see how to edit a property you already have.',
];
