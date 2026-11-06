<?php

/**
 * Training script - Deals 1 of 5: the Deal Register list, search, sort and opening a deal. Selectors walked on QA1 as the training agent. The training agency's one deal is invented (TrainingAgencySeeder).
 */
return [
    'key' => 'deals-register-find',
    'title' => 'Find a deal',
    'route' => 'deals-dr2.index',
    'url' => '/deals-dr2',
    'specs' => ['.ai/specs/deal-register-v2-spec.md'],
    'audience' => [],
    'related' => ['deals-create', 'deals-pipeline-list'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to find a deal in the Deal Register.',
    'steps' => [
        [
            'selector' => 'input[name="search"]', 'action' => 'type', 'done' => 'input', 'value' => 'Bianca',
            'narration' => 'Type the seller, the buyer, the address or the deal number.',
            'why' => 'You find the deal while the attorney is still on the phone.',
        ],
        [
            'selector' => 'select[name="status"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Choose a status, like Pending or Granted.',
            'why' => 'You see every deal that still needs your attention.',
        ],
        [
            'selector' => 'select[name="agent"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Or choose an agent to see only their deals.',
            'why' => 'A manager can check a whole team in seconds.',
        ],
        [
            'selector' => 'main a[href*="sort=property_value"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press a heading to sort, like Price or Property.',
            'why' => 'The biggest deals come to the top.',
        ],
        [
            'selector' => 'main a[href$="/edit"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press the deal number to open the deal.',
            'why' => 'Everything about the sale is kept on that one page.',
        ],
        [
            'selector' => 'main a[href*="/pipeline/view"]', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Pipeline to see the steps still to do.',
            'why' => 'It shows what is holding the deal up.',
        ],
    ],
    'outro' => 'Next, see how to add a new deal.',
];
