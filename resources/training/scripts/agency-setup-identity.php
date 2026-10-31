<?php

/**
 * AT-526 - the second pilot of the narrated guided tour: the first real step of the agency Setup Wizard (agency-onboarding-setup.md, step 1 "Your agency identity").
 * Tour only (`narrated`): `approved` stays false, so no video is made of it. The wording is the plain-words rule (training-videos.md 9.13); Johan reads it before it is trusted.
 * Every field is picked by its stable id on the wizard step. The tour never saves for the agent: the last step only points at the Save button.
 */
return [
    'key' => 'agency-setup-identity',
    'title' => 'Set up your agency details',
    'route' => null,
    'url' => '/corex/agency-setup/step/identity',
    'tour' => null,
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => true,
    'intro' => 'Let us set up your agency details. It takes about two minutes.',
    'steps' => [
        [
            'selector' => '#f_trading_name', 'action' => 'type', 'value' => 'Coastal Homes', 'done' => 'input',
            'narration' => 'Type the name your clients know you by.',
            'why' => 'This name goes on your documents and on emails to clients.',
        ],
        [
            'selector' => '#f_legal_name', 'action' => 'type', 'value' => 'Coastal Homes (Pty) Ltd', 'done' => 'input',
            'narration' => 'Type your full company name.',
            'why' => 'Contracts must carry the legal name, so we put it there for you.',
        ],
        [
            'selector' => '#f_short_code', 'action' => 'type', 'value' => 'CH', 'done' => 'input',
            'narration' => 'Choose two or three letters for your document numbers.',
            'why' => 'Every mandate and lease number starts with these letters, so you can tell them apart.',
        ],
        [
            'selector' => '#f_email', 'action' => 'type', 'value' => 'office@coastalhomes.example', 'done' => 'input',
            'narration' => 'Type the office email address.',
            'why' => 'Clients reply to this address, so use one that somebody reads every day.',
        ],
        [
            'selector' => '#f_phone', 'action' => 'type', 'value' => '039 000 0000', 'done' => 'input',
            'narration' => 'Type the office phone number.',
            'why' => 'It prints on your documents so clients know how to reach you.',
        ],
        [
            'selector' => '#f_ffc_no', 'action' => 'type', 'value' => '2026 00000 0000', 'done' => 'input',
            'narration' => 'Type your Fidelity Fund Certificate number.',
            'why' => 'The law says it must be on your documents, so CoreX adds it for you.',
        ],
        [
            'selector' => '.setup-cta', 'action' => 'highlight', 'done' => 'none',
            'narration' => 'When you are happy, press Save and continue.',
            'why' => 'Your details are saved, and you move on to the next step.',
        ],
    ],
    'outro' => 'Well done. Carry on to the next step when you are ready.',
];
