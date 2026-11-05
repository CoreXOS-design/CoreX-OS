<?php

/**
 * Training script - Properties 2 of 4: open a property and edit its details. Selectors walked on QA1 as the training agent. The recording opens the first property in the
 * list, points at the fields and the Save button, and never types or saves: nothing changes.
 */
return [
    'key' => 'properties-edit',
    'title' => 'Edit a property',
    'route' => 'corex.properties.show',
    'url' => '/corex/properties?flow=edit',
    'specs' => ['.ai/specs/property-page-redesign.md'],
    'audience' => [],
    'related' => ['properties-load', 'properties-photos', 'properties-status'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to change the details of a property.',
    'steps' => [
        [
            'selector' => '[data-tour="re-properties-list"] a.corex-btn-outline', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the property from your list.',
            'why' => 'Everything about the house lives on this one page.',
        ],
        [
            'selector' => '[data-tour="prop-tab-info"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press the Info tab.',
            'why' => 'This is where the details you can change are kept.',
        ],
        [
            'selector' => '[data-tour="prop-info-title"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Change the headline here.',
            'why' => 'It updates on your website and the portals when you refresh.',
        ],
        [
            'selector' => '[data-tour="prop-info-price"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Change the price here.',
            'why' => 'A price drop is also how CoreX finds buyers to tell.',
        ],
        [
            'selector' => '[data-tour="prop-spaces-features"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add rooms and features, like a pool or a garden.',
            'why' => 'Buyers search by these, so the more you add the more you match.',
        ],
        [
            'selector' => '[data-tour="prop-save"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Save when you are done.',
            'why' => 'Nothing changes for anyone else until you save.',
        ],
    ],
    'outro' => 'Next, see how to add photos.',
];
