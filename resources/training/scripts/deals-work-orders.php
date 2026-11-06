<?php

/**
 * Training script - Deals 5 of 5: ticking a certificate and sending its work order to a supplier or the transferring attorney. Ticking and choosing only change the screen until Save work orders is pressed, and the recording stops before it: nothing is saved or sent. Shares the List screen with deals-pipeline-list, so no route and a ?flow= url. The training agent's saved view is List (seeded), so the Pipeline link lands on it.
 */
return [
    'key' => 'deals-work-orders',
    'title' => 'Send a work order',
    'url' => '/deals-dr2?flow=work-orders',
    'specs' => ['.ai/specs/dr2-supplier-work-orders.md'],
    'audience' => [],
    'related' => ['deals-pipeline-list', 'deals-pipeline-timeline'],
    'login_as' => 'agent',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Here is how to send a work order to a supplier.',
    'steps' => [
        [
            'selector' => 'main a[href*="/pipeline/view"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open the deal\'s pipeline from the register.',
            'why' => 'One page shows every step from offer to transfer.',
        ],
        [
            'selector' => '[data-tour="pipe-tab-wo"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Open Supplier Work Orders.',
            'why' => 'Certificates are ordered from here, not by phone.',
        ],
        [
            'selector' => '[data-tour="pipe-wo-panel"] input[type="checkbox"]', 'action' => 'click', 'done' => 'click',
            'narration' => 'Tick the certificate the deal needs, like Electrical COC.',
            'why' => 'A ticked certificate becomes a step. An unticked one is not needed.',
        ],
        [
            'selector' => '[data-tour="pipe-wo-panel"] .dr2-wo-cols select', 'action' => 'select', 'done' => 'change', 'value' => 'supplier',
            'narration' => 'Choose who is responsible, like Supplier or the Transferring attorney.',
            'why' => 'The email goes to the right person.',
        ],
        [
            'selector' => '[data-tour="pipe-wo-panel"] .dr2-wo-cols > div:nth-child(2) select', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Pick the supplier. You can add a new one here.',
            'why' => 'The work order is emailed to them.',
        ],
        [
            'selector' => '[data-tour="pipe-wo-panel"] button.corex-btn-primary', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'Press Save work orders. Then you can send it now.',
            'why' => 'Or leave it, and it goes when its step is reached.',
        ],
    ],
    'outro' => 'That is a deal from offer to certificate.',
];
