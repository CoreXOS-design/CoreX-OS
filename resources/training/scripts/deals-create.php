<?php

/**
 * Training script - Deals 2 of 5: add a deal from an accepted offer. The recording only points at the fields: nothing is saved, and it ends on going back to the register.
 */
return [
    'key' => 'deals-create',
    'title' => 'Add a deal',
    'route' => 'deals-dr2.create',
    'url' => '/deals-dr2/create',
    'specs' => ['.ai/specs/deal-register-v2-spec.md'],
    'audience' => [],
    'related' => ['deals-register-find', 'deals-pipeline-list'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to add a deal when an offer is accepted.',
    'steps' => [
        [
            'selector' => '#dr2-prop', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Start with the property. Search for it by address.',
            'why' => 'The deal is linked to the house, so its history stays together.',
        ],
        [
            'selector' => '#dr2-seller', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Search for the seller, or add a new contact.',
            'why' => 'Both are linked, so you never type a name twice.',
        ],
        [
            'selector' => '#dr2-buyer', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add the buyer the same way.',
            'why' => 'The buyer is linked to the deal as well.',
        ],
        [
            'selector' => '#dr2_property_value', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Type the sale price.',
            'why' => 'The commission is worked out from it.',
        ],
        [
            'selector' => '#dr2_commission_percent', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Add the commission percentage.',
            'why' => 'CoreX shows the amount with and without VAT.',
        ],
        [
            'selector' => '#dr2-pipeline-select', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose a pipeline, like Standard Bond Sale.',
            'why' => 'The steps from offer to transfer are built for you.',
        ],
        [
            'selector' => 'select[name="accepted_status"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Set the status. It starts as Pending.',
            'why' => 'It moves to Granted when the conditions are met.',
        ],
        [
            'selector' => '#dr2-main-submit', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Save Deal when everything is right.',
            'why' => 'The pipeline starts the moment the deal is saved.',
        ],
        [
            'selector' => 'main a[href$="/deals-dr2"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Changed your mind? Go back. Nothing is saved until you press Save Deal.',
            'why' => 'You can start again without leaving a half-made deal.',
        ],
    ],
    'outro' => 'Next, see the pipeline of a deal.',
];
