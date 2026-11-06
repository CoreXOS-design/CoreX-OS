<?php

/**
 * Training script - Properties 4 of 4: change a property's status. Selectors walked on QA1 as the training agent. Route: none on purpose (the property page's route belongs
 * to the "Edit a property" video; ?flow=status keeps this one apart). Nothing is changed: the recording points at the status field and the Save button.
 */
return [
    'key' => 'properties-status',
    'title' => 'Change a property\'s status',
    'url' => '/corex/properties?flow=status',
    'specs' => ['.ai/specs/property-page-redesign.md'],
    'audience' => [],
    'related' => ['properties-load', 'properties-edit', 'properties-photos'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to change a property\'s status.',
    'steps' => [
        [
            'selector' => '[data-tour="re-properties-kpis"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'These numbers count your properties by status. Press one to see only those.',
            'why' => 'You see at a glance what is on the market and what has sold.',
        ],
        [
            'selector' => '[data-tour="re-properties-list"] a.corex-btn-outline', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the property you want to change.',
            'why' => 'The status lives on the property\'s own page.',
        ],
        [
            'selector' => '[data-tour="prop-tab-info"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Press the Info tab.',
            'why' => 'The status sits with the mandate details.',
        ],
        [
            'selector' => '[data-tour="prop-status"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose the new status, like On Market or Sold.',
            'why' => 'It changes what buyers see, and which of your lists the house is in.',
        ],
        [
            'selector' => '[data-tour="prop-mandate-type"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Check the mandate type and its end date.',
            'why' => 'CoreX reminds you before a mandate runs out.',
        ],
        [
            'selector' => '[data-tour="prop-save"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Save.',
            'why' => 'The new status shows everywhere straight away.',
        ],
    ],
    'outro' => 'That is how a property moves through its life in CoreX.',
];
