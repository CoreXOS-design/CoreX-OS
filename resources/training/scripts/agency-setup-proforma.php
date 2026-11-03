<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-proforma',
    'title' => 'Set up your invoices',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/proforma',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => false,
    'intro' => 'Now set up how your proforma invoices look.',
    'steps' => [
        [
            'selector' => '#f_number_prefix',
            'action' => 'type',
            'done' => 'input',
            'value' => 'BR-',
            'narration' => 'Type the letters your invoice numbers start with.',
            'why' => 'You and your clients can tell your invoices apart.',
        ],
        [
            'selector' => '#f_start_number',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type the number to start counting from.',
            'why' => 'If you already have invoices, carry on from your last one.',
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
            'action' => 'type',
            'done' => 'input',
            'value' => 'Sample Bank, account 000 000 0000',
            'narration' => 'Type your bank details.',
            'why' => 'Your clients need them to pay you.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your answers are kept, and you move on to the next screen.',
        ],
    ],
    'outro' => 'Next: your rentals.',
];
