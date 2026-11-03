<?php

/**
 * AT-526 / AT-525 training script - FINALISED 3 Nov by lane, Johan to spot-check videos. Selectors checked against the live screen on QA1 (as the training admin);
 * wording follows the plain-words rule (training-videos.md 9.13): short lines, what to click or type, and a "why". Invented sample data only. Under the two-minute cap.
 */
return [
    'key' => 'agency-setup-identity',
    'title' => 'Set up your agency details',
    // route: none on purpose - all the Setup Wizard steps share the route name corex.agency-setup.step, so a route match would pick the wrong step's video.
    // The "Watch how" button finds this one by its url below (TrainingScriptRepository::forPath).
    'url' => '/corex/agency-setup/step/identity',
    'tour' => null,
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'admin',
    'approved' => true,
    'narrated' => true,
    'intro' => 'Let us set up your agency details. It takes about two minutes.',
    'steps' => [
        [
            'selector' => '#f_trading_name',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Bluegum Realty',
            'narration' => 'Type the name your clients know you by.',
            'why' => 'This name goes on your documents and on emails to clients.',
        ],
        [
            'selector' => '#f_legal_name',
            'action' => 'type',
            'done' => 'input',
            'value' => 'Bluegum Realty (Pty) Ltd',
            'narration' => 'Type your full company name.',
            'why' => 'Contracts must carry the legal name, so CoreX puts it there for you.',
        ],
        [
            'selector' => '#f_short_code',
            'action' => 'type',
            'done' => 'input',
            'value' => 'BR',
            'narration' => 'Type two or three letters for your document numbers.',
            'why' => 'Every mandate and lease number starts with these letters, so you can tell them apart.',
        ],
        [
            'selector' => '#f_email',
            'action' => 'type',
            'done' => 'input',
            'value' => 'office@bluegumrealty.example',
            'narration' => 'Type the office email address.',
            'why' => 'Clients reply to this address, so use one somebody reads every day.',
        ],
        [
            'selector' => '#f_phone',
            'action' => 'type',
            'done' => 'input',
            'value' => '039 000 0000',
            'narration' => 'Type the office phone number.',
            'why' => 'It prints on your documents so clients know how to reach you.',
        ],
        [
            'selector' => '#f_ffc_no',
            'action' => 'type',
            'done' => 'input',
            'value' => '2026 00000 0000',
            'narration' => 'Type your agency Fidelity Fund Certificate number.',
            'why' => 'The law says it must be on your documents, so CoreX adds it for you.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'When you are happy, press Save and continue.',
            'why' => 'Your details are saved, and every document uses them from now on.',
        ],
    ],
    'outro' => 'Next: choose what your agency uses.',
];
