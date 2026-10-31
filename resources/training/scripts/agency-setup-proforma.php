<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-proforma',
    'title' => 'Set up your invoices',
    'url' => '/corex/agency-setup/step/proforma',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'Now set up how your proforma invoices look.',
    'steps' => [
        [
            'selector' => '#f_number_prefix',
            'action' => 'type',
            'done' => 'input',
            'value' => 'INV',
            'narration' => 'Type the letters your invoice numbers start with.',
            'why' => 'You and your clients can tell your invoices apart.',
        ],
        [
            'selector' => '#f_due_date_rule',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose when an invoice falls due.',
            'why' => 'The due date prints on every invoice.',
        ],
        [
            'selector' => '#f_bank_details',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type your bank details.',
            'why' => 'Your clients need them to pay you.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'Good. Carry on to the next step when you are ready.',
];
