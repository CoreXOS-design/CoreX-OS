<?php

/**
 * AT-526 - DRAFT narrated-tour script (prep only, 31 Oct). `narrated` and `approved` are both false: no "Guide me" button, no voice clips, no video until Johan approves the pilot and
 * the CoreX voice. Plain words (training-videos.md 9.13). To go live: set narrated => true (tour) and approved => true (video) after Johan has read the wording.
 */
return [
    'key' => 'agency-setup-roles',
    'title' => 'See who can do what',
    'url' => '/corex/agency-setup/step/roles',
    'specs' => ['.ai/specs/agency-onboarding-setup.md'],
    'audience' => [],
    'related' => [],
    'login_as' => 'agent',
    'approved' => false,
    'narrated' => false,
    'intro' => 'This screen explains roles. There is nothing to fill in.',
    'steps' => [
        [
            'selector' => 'main h3',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'These are the roles your agency starts with.',
            'why' => 'Each role decides what a person can see and do.',
        ],
        [
            'selector' => '.setup-cta',
            'action' => 'highlight',
            'done' => 'none',
            'narration' => 'Press Save and continue.',
            'why' => 'Your choices are kept and you move on to the next step.',
        ],
    ],
    'outro' => 'Next, learn how to create a role in Role Manager.',
];
