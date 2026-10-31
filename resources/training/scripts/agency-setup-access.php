<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-access',
    'title' => 'Finish your setup',
    'url' => '/corex/agency-setup/step/access',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'One last choice, and your agency is set up.',
    'steps' => [
        [
            'selector' => 'label:has(#f_desk_enabled)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if agents may share their screen with CoreX support.',
            'why' => 'Support can then see the problem and fix it with them.',
        ],
        [
            'selector' => '#f_desk_control_idle_minutes',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Type how many idle minutes before a support take-over ends.',
            'why' => 'Support never keeps control of a screen by mistake.',
        ],
        [
            'selector' => 'label:has(#f_require_external_access_authorization)',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Choose if a developer must ask your permission first.',
            'why' => 'Nobody enters your agency without you knowing.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Finish setup.',
            'why' => 'Your agency is ready and you land on your dashboard.',
        ],
    ],
    'outro' => 'You are all set.',
];
